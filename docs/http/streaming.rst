Streaming
=========

Download
--------

Two modes:

- ``downloadTo($path)`` buffers the response body in memory, then publishes it
  through a temporary file and atomic rename.
- ``streamDownloadTo($path)`` streams into a temporary file and publishes it
  atomically after the complete request succeeds.

Use response size bounds:

- ``maxResponseBytes($bytes)``
- ``maxDownloadBytes($bytes)``

Upload
------

Direct upload helpers:

- ``uploadFromFile($path)``
- ``uploadFromStream($stream, $size)``
- ``maxUploadBytes($bytes)``

Multipart
---------

Use ``MultipartBody`` explicitly for multipart construction.

.. code-block:: php

   use Infocyph\TalkingBytes\Http\Body\MultipartBody;
   use Infocyph\TalkingBytes\Http\HttpRequest;

   $multipart = MultipartBody::new()
       ->addField('name', 'report')
       ->addFile('file', '/tmp/report.pdf');

   $request = HttpRequest::post('https://api.example.com/upload')->multipart($multipart);

Notes
-----

- Multipart stream/data parts are materialized into temporary files before cURL transfer.
- Streamed download mode cleans temporary files on failure paths.

Download publication semantics
------------------------------

Both buffered and streamed downloads replace the requested target only after
the complete HTTP transaction is accepted as successful. For redirect chains,
intermediate response bodies are never published; only the final accepted
response can become the target artifact.

Transport failures, truncated final responses, HTTP error responses,
size-limit failures, cancellation, redirect loops, and blocked redirect
destinations discard temporary output. Existing targets remain unchanged and a
failed request does not create a previously absent target.
