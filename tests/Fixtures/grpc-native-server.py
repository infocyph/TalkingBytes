#!/usr/bin/env python3
import json
import sys
import time
from concurrent import futures

import grpc

ready_path = sys.argv[1]


def identity(value: bytes) -> bytes:
    return value


def unary(request: bytes, context: grpc.ServicerContext) -> bytes:
    context.send_initial_metadata((("x-header", "unary"),))
    context.set_trailing_metadata((("x-trailer", "unary-end"),))
    return b""


def server_stream(request: bytes, context: grpc.ServicerContext):
    context.send_initial_metadata((("x-header", "server"),))
    context.set_trailing_metadata((("x-trailer", "server-end"),))
    yield b""
    yield b""


def client_stream(requests, context: grpc.ServicerContext) -> bytes:
    count = sum(1 for _ in requests)
    context.send_initial_metadata((("x-header", "client"),))
    context.set_trailing_metadata((("x-count", str(count)),))
    return b""


def bidi_stream(requests, context: grpc.ServicerContext):
    context.send_initial_metadata((("x-header", "bidi"),))
    count = 0
    for _ in requests:
        count += 1
        yield b""
    context.set_trailing_metadata((("x-count", str(count)),))


def slow_server_stream(request: bytes, context: grpc.ServicerContext):
    time.sleep(0.25)
    yield b""


handlers = {
    "Unary": grpc.unary_unary_rpc_method_handler(
        unary,
        request_deserializer=identity,
        response_serializer=identity,
    ),
    "Server": grpc.unary_stream_rpc_method_handler(
        server_stream,
        request_deserializer=identity,
        response_serializer=identity,
    ),
    "Client": grpc.stream_unary_rpc_method_handler(
        client_stream,
        request_deserializer=identity,
        response_serializer=identity,
    ),
    "Bidi": grpc.stream_stream_rpc_method_handler(
        bidi_stream,
        request_deserializer=identity,
        response_serializer=identity,
    ),
    "SlowServer": grpc.unary_stream_rpc_method_handler(
        slow_server_stream,
        request_deserializer=identity,
        response_serializer=identity,
    ),
}

server = grpc.server(futures.ThreadPoolExecutor(max_workers=8))
server.add_generic_rpc_handlers((
    grpc.method_handlers_generic_handler("talkingbytes.Integration", handlers),
))
port = server.add_insecure_port("127.0.0.1:0")
if port <= 0:
    raise RuntimeError("Unable to bind native gRPC integration server.")

server.start()
with open(ready_path, "w", encoding="utf-8") as handle:
    json.dump({"port": port}, handle)

try:
    server.wait_for_termination()
except KeyboardInterrupt:
    server.stop(grace=0)
