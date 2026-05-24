<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use DateTimeInterface;

final readonly class MailboxSearch
{
    /**
     * @param list<string> $criteria
     */
    private function __construct(
        public array $criteria = [],
        public ?int $limit = null,
        public string $sortOrder = 'uid_asc',
        public ?int $maxSummaryFetches = null,
        public ?int $maxClientSideFilterFetches = null,
        public bool $requireExplicitLimitForExpensiveSearch = false,
    ) {}

    public static function new(): self
    {
        return new self();
    }

    public function all(): self
    {
        return $this->append('ALL');
    }

    public function answered(): self
    {
        return $this->append('ANSWERED');
    }

    public function bcc(string $value): self
    {
        return $this->append('BCC ' . ImapStringEscaper::quote($value));
    }

    public function before(DateTimeInterface $date): self
    {
        return $this->append('BEFORE ' . $date->format('d-M-Y'));
    }

    public function bodyContains(string $value): self
    {
        return $this->append('BODY ' . ImapStringEscaper::quote($value));
    }

    public function cc(string $value): self
    {
        return $this->append('CC ' . ImapStringEscaper::quote($value));
    }

    public function deleted(): self
    {
        return $this->append('DELETED');
    }

    public function flagged(): self
    {
        return $this->append('FLAGGED');
    }

    public function from(string $value): self
    {
        return $this->append('FROM ' . ImapStringEscaper::quote($value));
    }

    public function hasAttachment(): self
    {
        return $this->append('BODY "Content-Disposition: attachment"');
    }

    public function keyword(string $value): self
    {
        return $this->append('KEYWORD ' . ImapStringEscaper::quote($value));
    }

    public function largerThan(int $bytes): self
    {
        if ($bytes < 1) {
            return $this;
        }

        return $this->append('LARGER ' . $bytes);
    }

    public function limit(int $value): self
    {
        return $this->recreate(limit: $value > 0 ? $value : null);
    }

    public function maxClientSideFilterFetches(int $value): self
    {
        return $this->recreate(maxClientSideFilterFetches: $value > 0 ? $value : null);
    }

    public function maxSummaryFetches(int $value): self
    {
        return $this->recreate(maxSummaryFetches: $value > 0 ? $value : null);
    }

    public function newestFirst(): self
    {
        return $this->recreate(sortOrder: 'uid_desc');
    }

    public function oldestFirst(): self
    {
        return $this->recreate(sortOrder: 'uid_asc');
    }

    public function on(DateTimeInterface $date): self
    {
        return $this->append('ON ' . $date->format('d-M-Y'));
    }

    public function requireExplicitLimitForExpensiveSearch(bool $enabled = true): self
    {
        return $this->recreate(requireExplicitLimitForExpensiveSearch: $enabled);
    }

    public function seen(): self
    {
        return $this->append('SEEN');
    }

    public function since(DateTimeInterface $date): self
    {
        return $this->append('SINCE ' . $date->format('d-M-Y'));
    }

    public function smallerThan(int $bytes): self
    {
        if ($bytes < 1) {
            return $this;
        }

        return $this->append('SMALLER ' . $bytes);
    }

    public function sortByDateAsc(): self
    {
        return $this->recreate(sortOrder: 'date_asc');
    }

    public function sortByDateDesc(): self
    {
        return $this->recreate(sortOrder: 'date_desc');
    }

    public function subjectContains(string $value): self
    {
        return $this->append('SUBJECT ' . ImapStringEscaper::quote($value));
    }

    public function textContains(string $value): self
    {
        return $this->append('TEXT ' . ImapStringEscaper::quote($value));
    }

    public function to(string $value): self
    {
        return $this->append('TO ' . ImapStringEscaper::quote($value));
    }

    public function toCriteriaString(): string
    {
        if ($this->criteria === []) {
            return 'ALL';
        }

        return implode(' ', $this->criteria);
    }

    public function uidAsc(): self
    {
        return $this->recreate(sortOrder: 'uid_asc');
    }

    public function uidDesc(): self
    {
        return $this->recreate(sortOrder: 'uid_desc');
    }

    public function uidRange(int $start, int $end): self
    {
        if ($start < 1 || $end < 1) {
            return $this;
        }

        $range = $start <= $end ? sprintf('%d:%d', $start, $end) : sprintf('%d:%d', $end, $start);

        return $this->append('UID ' . $range);
    }

    public function unanswered(): self
    {
        return $this->append('UNANSWERED');
    }

    public function undeleted(): self
    {
        return $this->append('UNDELETED');
    }

    public function unflagged(): self
    {
        return $this->append('UNFLAGGED');
    }

    public function unkeyword(string $value): self
    {
        return $this->append('UNKEYWORD ' . ImapStringEscaper::quote($value));
    }

    public function unseen(): self
    {
        return $this->append('UNSEEN');
    }

    private function append(string $criterion): self
    {
        $criteria = $this->criteria;
        $criteria[] = $criterion;

        return $this->recreate(criteria: $criteria);
    }

    /**
     * @param list<string>|null $criteria
     */
    private function recreate(
        ?array $criteria = null,
        ?int $limit = null,
        ?string $sortOrder = null,
        ?int $maxSummaryFetches = null,
        ?int $maxClientSideFilterFetches = null,
        ?bool $requireExplicitLimitForExpensiveSearch = null,
    ): self {
        return new self(
            $criteria ?? $this->criteria,
            $limit ?? $this->limit,
            $sortOrder ?? $this->sortOrder,
            $maxSummaryFetches ?? $this->maxSummaryFetches,
            $maxClientSideFilterFetches ?? $this->maxClientSideFilterFetches,
            $requireExplicitLimitForExpensiveSearch ?? $this->requireExplicitLimitForExpensiveSearch,
        );
    }
}
