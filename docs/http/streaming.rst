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

Both buffered and streamed downloads publish through a temporary file and
replace the requested target only after the HTTP transfer is accepted as a
successful result. Transport failures, HTTP error responses, size-limit
failures, cancellation and redirect failures discard temporary output and
preserve an existing target.
