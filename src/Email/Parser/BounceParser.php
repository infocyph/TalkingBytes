<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

use Infocyph\TalkingBytes\Email\Enum\BounceType;
use Infocyph\TalkingBytes\Email\Event\EmailEventBus;
use Infocyph\TalkingBytes\Email\ValueObject\BounceReport;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail;

final readonly class BounceParser
{
    public function __construct(private DeliveryStatusParser $deliveryStatusParser = new DeliveryStatusParser()) {}

    public function parse(ParsedEmail $email): ?BounceReport
    {
        $reports = $this->parseMany($email);

        return $reports[0] ?? null;
    }

    /**
     * @return list<BounceReport>
     */
    public function parseMany(ParsedEmail $email): array
    {
        $deliveryStatuses = $this->deliveryStatusFromParts($email);
        if ($deliveryStatuses !== []) {
            $reports = [];
            foreach ($deliveryStatuses as $deliveryStatus) {
                $status = $deliveryStatus['status'] ?? null;
                $diagnostic = $deliveryStatus['diagnostic_code'] ?? null;

                $reports[] = new BounceReport(
                    $this->classify($status, $diagnostic),
                    $deliveryStatus['final_recipient'] ?? ($deliveryStatus['original_recipient'] ?? null),
                    $deliveryStatus['action'] ?? null,
                    $status,
                    $diagnostic,
                    $deliveryStatus['remote_mta'] ?? ($deliveryStatus['reporting_mta'] ?? null),
                    $email->messageId,
                    [
                        'source' => 'delivery-status',
                        'reporting_mta' => $deliveryStatus['reporting_mta'] ?? null,
                        'last_attempt_date' => $deliveryStatus['last_attempt_date'] ?? null,
                        'will_retry_until' => $deliveryStatus['will_retry_until'] ?? null,
                    ],
                );
            }

            foreach ($reports as $report) {
                $this->dispatchDetectedEvent($report);
            }

            return $reports;
        }

        if ($email->isBounceCandidate()) {
            $text = strtolower(trim($email->textBody ?? ''));
            if ($text === '' && trim($email->subjectOrEmpty()) === '') {
                return [];
            }

            $recipient = $this->extractRecipientFromText($text);
            $status = $this->extractEnhancedStatusFromText($text);
            $diagnostic = $this->extractDiagnosticFromText($text);

            $report = new BounceReport(
                $this->classify($status, $diagnostic ?: $email->subjectOrEmpty()),
                $recipient,
                $status !== null && str_starts_with($status, '4.') ? 'delayed' : 'failed',
                $status,
                $diagnostic,
                null,
                $email->messageId,
                ['source' => 'heuristic-text'],
            );
            $this->dispatchDetectedEvent($report);

            return [$report];
        }

        return [];
    }

    private function classify(?string $status, ?string $diagnostic): BounceType
    {
        $statusText = strtolower($status ?? '');
        $diagnosticText = strtolower($diagnostic ?? '');

        $statusSpecific = $this->classifyBySpecificStatus($statusText);
        if ($statusSpecific !== null) {
            return $statusSpecific;
        }

        $diagnosticClassification = $this->classifyByDiagnostic($diagnosticText);
        if ($diagnosticClassification !== BounceType::Unknown) {
            return $diagnosticClassification;
        }

        $statusClassification = $this->classifyByGenericStatus($statusText);
        if ($statusClassification !== null) {
            return $statusClassification;
        }

        return BounceType::Unknown;
    }

    private function classifyByDiagnostic(string $diagnostic): BounceType
    {
        if (str_contains($diagnostic, 'mailbox full')) {
            return BounceType::MailboxFull;
        }

        if (str_contains($diagnostic, 'user unknown') || str_contains($diagnostic, 'no such user')) {
            return BounceType::UserUnknown;
        }

        if (str_contains($diagnostic, 'domain not found') || str_contains($diagnostic, 'host not found')) {
            return BounceType::DomainNotFound;
        }

        if (str_contains($diagnostic, 'spam') || str_contains($diagnostic, 'blocked') || str_contains($diagnostic, 'policy')) {
            return BounceType::SpamRejected;
        }

        if (str_contains($diagnostic, 'temporarily') || str_contains($diagnostic, 'try again later')) {
            return BounceType::Temporary;
        }

        return BounceType::Unknown;
    }

    private function classifyByGenericStatus(string $status): ?BounceType
    {
        if (preg_match('/\b5\.[0-9]+\.[0-9]+\b/', $status) === 1) {
            return BounceType::Hard;
        }

        if (preg_match('/\b4\.[0-9]+\.[0-9]+\b/', $status) === 1) {
            return BounceType::Soft;
        }

        return null;
    }

    private function classifyBySpecificStatus(string $status): ?BounceType
    {
        if (str_contains($status, '5.1.1')) {
            return BounceType::UserUnknown;
        }

        if (str_contains($status, '5.2.2') || str_contains($status, '4.2.2')) {
            return BounceType::MailboxFull;
        }

        if (str_contains($status, '5.1.2')) {
            return BounceType::DomainNotFound;
        }

        return null;
    }

    /**
     * @return list<array{
     *   final_recipient:string|null,
     *   original_recipient:string|null,
     *   action:string|null,
     *   status:string|null,
     *   diagnostic_code:string|null,
     *   remote_mta:string|null,
     *   reporting_mta:string|null,
     *   last_attempt_date:string|null,
     *   will_retry_until:string|null
     * }>
     */
    private function deliveryStatusFromParts(ParsedEmail $email): array
    {
        foreach ($email->parts as $part) {
            if ($part->contentType !== 'message/delivery-status') {
                continue;
            }

            return $this->deliveryStatusParser->parseMany($part->body);
        }

        return [];
    }

    private function dispatchDetectedEvent(BounceReport $report): void
    {
        EmailEventBus::dispatch('bounce.detected', [
            'type' => $report->type->value,
            'recipient' => $report->recipient,
            'status' => $report->status,
            'message_id' => $report->originalMessageId,
        ]);
    }

    private function extractDiagnosticFromText(string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        foreach (['diagnostic-code:', 'reason:', 'error:'] as $label) {
            if (preg_match('/' . preg_quote($label, '/') . '\s*(.+)$/mi', $text, $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    private function extractEnhancedStatusFromText(string $text): ?string
    {
        if (preg_match('/\b([245]\.[0-9]+\.[0-9]+)\b/', $text, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function extractRecipientFromText(string $text): ?string
    {
        if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,63}/i', $text, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[0]);
    }
}
