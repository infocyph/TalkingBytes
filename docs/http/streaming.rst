Streaming
=========

Download
--------

Two modes:

- ``downloadTo($path)`` for straightforward writes
- ``streamDownloadTo($path)`` for temp-file streaming + atomic finalize

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
