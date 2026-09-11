from pathlib import Path

path = Path('docs/plans/runwire-1.0-foundation-3-launch-plan.md')
text = path.read_text()

text = text.replace(
    '- HTTP/1.1 wire parsing/serialization required for a native HTTP server;',
    '- HTTP/1.1 and HTTP/2 wire parsing/serialization required for the native HTTP server;'
)

text = text.replace(
    'HTTP/1.1  REQUIRED\nHTTP/2    REQUIRED\nHTTP/3    FUTURE / NON-BLOCKING FOR 1.0',
    'HTTP/1.1  REQUIRED\nHTTP/2    REQUIRED'
)

start = text.find('## 10.12 HTTP/3 future boundary')
if start != -1:
    end = text.find('\n---\n\n# 11.', start)
    if end == -1:
        raise SystemExit('Could not find end of HTTP/3 subsection')
    text = text[:start] + text[end + 1:]

text = text.replace('supports_http3\n', '')

future = '''# 42. Future plan after Runwire 1.0\n\nThe following items are intentionally outside the Runwire 1.0 / Foundation 3 launch gate. They must not leak into current 1.0 capability promises, completion criteria, or implementation blockers.\n\n## 42.1 HTTP/3 / QUIC\n\nHTTP/3 is the next native HTTP protocol target after Runwire 1.0 stabilizes HTTP/1.1 and HTTP/2.\n\nFuture ownership remains consistent:\n\n```text\nHTTP/1.1 -> TCP/TLS -> Runwire HTTP/1 engine\nHTTP/2   -> TCP/TLS -> Runwire HTTP/2 engine\nHTTP/3   -> QUIC/UDP/TLS 1.3 -> future Runwire HTTP/3 engine\n```\n\nThe future HTTP/3 implementation must normalize into the same version-neutral Runwire HTTP transport consumed by Webrick so Foundation application semantics do not change.\n\nFuture HTTP/3 work should cover, after a dedicated design/review pass:\n\n- QUIC transport over UDP rather than pretending HTTP/3 is another TCP framing layer;\n- TLS 1.3 handshake and QUIC cryptographic integration through a mature, supportable implementation path;\n- bidirectional and unidirectional QUIC stream lifecycle;\n- HTTP/3 control streams and SETTINGS;\n- QPACK encoder/decoder and blocked-stream accounting;\n- connection-level and stream-level flow control/backpressure;\n- connection IDs, migration/rebinding policy where supported;\n- graceful connection drain and GOAWAY semantics;\n- cancellation/reset/STOP_SENDING handling;\n- 0-RTT policy and replay-safety boundaries;\n- bounded QPACK dynamic-table/header-list state;\n- stream-count, control-frame and CPU/work amplification limits;\n- QUIC/HTTP/3 abuse/flood resistance and memory ceilings;\n- optional HTTP Datagrams/WebTransport only through later explicit capability work;\n- future `supports_http3` / `owns_http3_wire` capability reporting only once implemented and production-ready;\n- host-driver passthrough semantics when FrankenPHP, RoadRunner or another host terminates HTTP/3 outside Runwire;\n- HTTP/1.1 / HTTP/2 / HTTP/3 Webrick application-semantic parity;\n- dedicated interoperability, soak and benchmark suites.\n\nDo not select a QUIC dependency, extension, FFI binding, sidecar, or implementation strategy in the 1.0 plan merely to reserve HTTP/3. That choice requires a separate post-1.0 security, portability, maintenance and performance evaluation.\n\nHTTP/3 must not block Runwire 1.0.\n\n---\n\n'''

marker = '# 42. 1.0 completion gate\n'
if marker not in text:
    raise SystemExit('Completion gate marker not found')
text = text.replace(marker, future + '# 43. 1.0 completion gate\n', 1)
text = text.replace('# 43. Immediate implementation handoff\n', '# 44. Immediate implementation handoff\n', 1)

# Defensive verification: HTTP/3 may appear only in the future-plan section.
first = text.find('HTTP/3')
future_start = text.find('# 42. Future plan after Runwire 1.0')
if first != -1 and first < future_start:
    raise SystemExit('HTTP/3 reference remains outside Future Plan')

path.write_text(text)
Path('.github/http3_future_cleanup.py').unlink()
Path('.github/workflows/http3-future-cleanup.yml').unlink()
