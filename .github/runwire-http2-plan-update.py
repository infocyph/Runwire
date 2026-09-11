from pathlib import Path

path = Path('docs/plans/runwire-1.0-foundation-3-launch-plan.md')
text = path.read_text()


def replace_once(old: str, new: str) -> None:
    global text
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'Expected exactly one occurrence, found {count}: {old[:100]!r}')
    text = text.replace(old, new, 1)


replace_once(
    '- HTTP/1.1 wire parsing/serialization required for a native HTTP server;',
    '- HTTP/1.1 and HTTP/2 wire parsing/serialization required for the native HTTP server;\n- TLS ALPN negotiation for `h2` / `http/1.1` in native TLS mode;\n- HTTP/2 connection/stream state, HPACK, multiplexing, flow control, graceful drain and abuse limits;'
)

replace_once(
    '# 10. HTTP/1.1 wire protocol for Foundation/Webrick',
    '# 10. Native HTTP/1.1 + HTTP/2 protocol stack for Foundation/Webrick'
)

replace_once(
    '## 10.2 HTTP/2\n\nHTTP/2 is not required for Runwire 1.0/Foundation 3 launch unless implementation evidence shows it can be completed without delaying correctness/security acceptance.\n\nDesign protocol contracts so HTTP/2 can be added later without changing Webrick application semantics.',
    '''## 10.2 Protocol targets and standards baseline\n\nRunwire 1.0 native HTTP has two release-required wire protocols:\n\n```text\nHTTP/1.1  REQUIRED\nHTTP/2    REQUIRED\nHTTP/3    FUTURE / NON-BLOCKING FOR 1.0\n```\n\nStandards baseline:\n\n- HTTP semantics: RFC 9110;\n- HTTP/1.1 message syntax/routing: RFC 9112;\n- HTTP/2: RFC 9113;\n- HPACK: RFC 7541;\n- TLS ALPN: RFC 7301;\n- extensible HTTP priorities: RFC 9218 where/when priority signaling is consumed;\n- WebSocket over HTTP/2 extended CONNECT: RFC 8441 if/when that optional integration is enabled.\n\nDo not implement against obsolete RFC 7540 behavior where RFC 9113 intentionally changed/deprecated it. In particular, the old RFC 7540 dependency-tree priority scheme is deprecated and HTTP/1.1 Upgrade-to-`h2c` is not a required production path.\n\nThe common Runwire HTTP transport contract must prevent Webrick/Foundation from caring whether the request arrived through HTTP/1.1 or an HTTP/2 stream.\n\n```text\nHTTP/1.1 connection/request ─┐\n                             ├─ Runwire HTTP transport request/response contract -> Webrick\nHTTP/2 connection/stream ────┘\n```\n\nApplication routing, middleware, authentication, validation and response semantics remain above the wire protocol.\n\n---\n\n## 10.3 HTTP/2 negotiation and connection startup\n\nNative TLS listeners must support ALPN negotiation between at least:\n\n```text\nh2\nhttp/1.1\n```\n\nWhen both are enabled, Runwire should advertise both and prefer `h2` according to configured protocol order while remaining interoperable with HTTP/1.1 clients.\n\nRequired behavior:\n\n- ALPN-selected `h2` enters the HTTP/2 connection state machine directly;\n- ALPN-selected `http/1.1` enters the HTTP/1.1 parser;\n- unknown/unsupported negotiated protocols fail clearly;\n- TLS handshake timeout is bounded;\n- no protocol sniffing ambiguity after ALPN establishes the protocol;\n- protocol choice is immutable for that TCP/TLS connection.\n\nCleartext HTTP/2 policy:\n\n- prior-knowledge `h2c` may be supported as an explicit opt-in native listener capability;\n- HTTP/1.1 `Upgrade: h2c` is not required for 1.0 and should not be the default because RFC 9113 deprecates that upgrade path;\n- production documentation should recommend TLS + ALPN for public HTTP/2 service.\n\nThe HTTP/2 client connection preface and first SETTINGS exchange must be validated before application streams are accepted.\n\n---\n\n## 10.4 HTTP/2 frame engine\n\nImplement an incremental binary frame parser/writer with strict bounds. It must not require buffering an arbitrary connection payload before decoding frames.\n\nRequired frame support/handling:\n\n```text\nDATA\nHEADERS\nPRIORITY          protocol-compatible handling; legacy priority semantics are deprecated\nRST_STREAM\nSETTINGS\nPUSH_PROMISE      parse/protocol handling as required; server push is not a 1.0 app feature\nPING\nGOAWAY\nWINDOW_UPDATE\nCONTINUATION\nunknown extension frames according to RFC 9113 rules\n```\n\nRequirements:\n\n- validate the fixed frame header before allocating payload storage;\n- enforce peer/local maximum frame size before payload growth;\n- validate stream-ID rules for each frame type;\n- validate frame-specific length/flag combinations;\n- SETTINGS ACK and value rules are enforced;\n- PING payload length is enforced;\n- WINDOW_UPDATE increment zero/overflow is rejected correctly;\n- HEADERS/PUSH_PROMISE continuation blocks remain contiguous until END_HEADERS as required;\n- an unfinished header block cannot grow without a configured byte/frame/time ceiling;\n- unknown extension frames can be skipped without copying into unbounded buffers;\n- protocol errors are mapped to stream or connection failure according to RFC 9113 rather than crashing the worker.\n\nNo application code receives raw frame parser internals.\n\n---\n\n## 10.5 HTTP/2 stream state and multiplexing\n\nEach HTTP/2 exchange is an explicit connection-owned stream with a state machine covering the RFC-defined lifecycle, conceptually:\n\n```text\nidle\nreserved (where applicable)\nopen\nhalf-closed local\nhalf-closed remote\nclosed\n```\n\nRequirements:\n\n- client-initiated stream identifiers are validated and monotonic;\n- closed stream state is released promptly without losing protocol bookkeeping required to reject invalid reuse;\n- maximum concurrent streams is locally bounded regardless of peer behavior;\n- one stalled stream cannot block unrelated streams at the Runwire scheduler/application-dispatch layer;\n- stream cancellation propagates to the request/body producer where safe;\n- stream completion performs deterministic buffer/body/application callback cleanup;\n- connection close cancels/cleans every remaining stream exactly once;\n- stream objects do not become Foundation request scopes; they only carry transport state.\n\nRunwire must distinguish:\n\n```text\nconnection lifetime\n    ├─ stream 1 -> application execution A\n    ├─ stream 3 -> application execution B\n    └─ stream 5 -> application execution C\n```\n\nFoundation/Webrick must create separate logical request execution state for every stream even when streams overlap on one connection.\n\n---\n\n## 10.6 HPACK header compression\n\nProvide a dedicated HPACK implementation/component rather than mixing compression-table state into the general HTTP/2 connection class.\n\nConceptual internal split:\n\n```text\nHttp2\n ├─ FrameParser / FrameWriter\n ├─ ConnectionState\n ├─ StreamState\n ├─ FlowController\n └─ Hpack\n      ├─ Decoder\n      ├─ Encoder\n      ├─ DynamicTable\n      └─ Huffman decoder/encoder where implemented\n```\n\nHPACK requirements:\n\n- decoder dynamic table is connection-local;\n- encoder dynamic table is connection-local;\n- table size honors peer SETTINGS while also respecting a Runwire hard ceiling;\n- dynamic table updates are validated in the correct header-block position;\n- decoded header-list bytes/count are bounded independently from compressed bytes;\n- compressed input cannot cause unbounded decompressed allocation;\n- malformed integer/Huffman/string encodings fail deterministically;\n- Huffman decode has bounded work/output and rejects invalid terminal padding/state;\n- sensitive headers may use never-indexed encoding according to Runwire/Webrick policy;\n- HPACK state is destroyed with its owning connection and never shared globally across clients.\n\nDo not optimize HPACK by creating mutable global tables.\n\n---\n\n## 10.7 HTTP/2 request-header and pseudo-header validation\n\nBefore mapping an HTTP/2 request into the common Runwire HTTP request transport, validate HTTP/2-specific field semantics.\n\nAt minimum:\n\n- header field names obey HTTP/2 lowercase requirements;\n- pseudo-header fields appear before regular fields;\n- pseudo-header fields are not duplicated;\n- only request-appropriate pseudo-headers are accepted;\n- required `:method`, `:scheme`, `:path`, `:authority` combinations are validated according to request form/CONNECT semantics;\n- connection-specific HTTP/1.x fields are rejected where HTTP/2 forbids them;\n- `TE` is accepted only with the HTTP/2-permitted `trailers` value;\n- header-list count and decoded bytes remain under local hard ceilings even when the peer advertises larger values;\n- trailers are represented distinctly from initial request headers;\n- authority/host normalization does not create two conflicting routing authorities.\n\nHTTP/2 transport validation must not duplicate Webrick application validation.\n\n---\n\n## 10.8 HTTP/2 flow control and backpressure\n\nHTTP/2 flow control must compose with Runwire's existing connection backpressure instead of becoming a parallel unbounded buffering system.\n\nRunwire must track both:\n\n```text\nconnection flow-control window\nstream flow-control window\n```\n\nRequired behavior:\n\n- never transmit DATA beyond the peer-advertised connection or stream window;\n- inbound WINDOW_UPDATE changes credit without bypassing configured memory ceilings;\n- inbound DATA consumes receive credit before being accepted into application buffers;\n- replenish receive windows based on actual downstream consumption strategy, not simply because bytes were read from the socket;\n- per-stream outbound queues are bounded;\n- aggregate HTTP/2 connection outbound queue is bounded;\n- per-stream inbound/request-body buffering is bounded;\n- one slow stream cannot consume the entire connection/worker memory budget;\n- control frames required for protocol progress are not deadlocked behind DATA backpressure;\n- stream cancellation releases queued buffers and application body resources promptly.\n\nThe implementation should use a simple fair scheduler initially. Do not implement a complex priority tree that RFC 9113 has deprecated.\n\nIf RFC 9218 priority signals are later consumed, isolate them behind a scheduling policy so the core stream/flow-control state machine remains correct without them.\n\n---\n\n## 10.9 HTTP/2 graceful drain, reload and shutdown\n\nHTTP/2 must integrate with Runwire worker generations and graceful reload.\n\nRequired drain sequence conceptually:\n\n```text\nworker enters draining\n      ↓\nstop accepting new connections where appropriate\n      ↓\nsend GOAWAY with an appropriate last processed stream ID\n      ↓\nrefuse/reject new streams beyond drain boundary\n      ↓\nallow active streams to complete within grace deadline\n      ↓\nclose connection\n      ↓\nRunwire supervisor may terminate worker after deadline\n```\n\nRequirements:\n\n- GOAWAY state is explicit and idempotent;\n- multiple shutdown/reload requests do not corrupt last-stream accounting;\n- active streams have a bounded drain deadline;\n- client disconnect during drain cleans stream/application state;\n- graceful worker reload must not silently drop already accepted streams without the configured policy/deadline;\n- force termination remains available after grace expiration.\n\nPING may be used for protocol liveness/diagnostics but must not become an unbounded heartbeat flood.\n\n---\n\n## 10.10 HTTP/2 security and abuse resistance\n\nHTTP/2 expands the resource-amplification surface because many logical streams and control frames share one TCP connection. Release acceptance must therefore include explicit abuse controls.\n\nBound/configure at minimum:\n\n```text\nmax concurrent streams\nmax total streams created per connection / bounded churn policy\nmax frame size accepted under protocol limits\nmax compressed header-block bytes\nmax CONTINUATION frames per header block\nmax decoded header-list bytes\nmax decoded header count\nmax HPACK dynamic-table bytes\nmax pending request-body bytes per stream\nmax pending response bytes per stream\nmax pending aggregate bytes per connection\nmax SETTINGS/PING/RST_STREAM/WINDOW_UPDATE/control-frame rate or work budget\nheader-block completion timeout\nstream idle/request timeout\nconnection idle/lifetime policy\n```\n\nSpecific adversarial cases to cover:\n\n- rapid open/reset stream churn (HTTP/2 Rapid Reset style behavior);\n- RST_STREAM floods that repeatedly force expensive application setup/cleanup;\n- SETTINGS floods/ACK churn;\n- PING floods;\n- WINDOW_UPDATE floods/overflow attempts;\n- endless or excessive CONTINUATION/header blocks;\n- HPACK compression/decompression bombs;\n- oversized dynamic-table requests;\n- streams opened beyond the advertised/local concurrency ceiling;\n- invalid/reused/decreasing stream IDs;\n- empty-frame/control-frame CPU amplification;\n- request bodies that stall after headers;\n- outbound clients that stop reading while many streams are active.\n\nAbuse limits should count **work/state pressure**, not only raw socket bytes. Exceeding an abuse threshold should fail the affected stream when safe or send GOAWAY/close the connection when the connection itself is abusive.\n\nRunwire must avoid retaining attacker-controlled closed-stream objects indefinitely merely to remember historical state; use compact bounded bookkeeping sufficient for protocol correctness.\n\n---\n\n## 10.11 HTTP/2 server push and extended protocols\n\nDo not make HTTP/2 server push a Runwire 1.0 application feature. Runwire should remain protocol-correct around peer SETTINGS and must not emit PUSH_PROMISE from normal application responses in 1.0. This avoids committing Webrick/Foundation to an obsolete/poorly deployed application API.\n\nWebSocket over HTTP/2 using RFC 8441 extended CONNECT is a valid future/optional capability. If implemented in 1.0, it must:\n\n- be capability-negotiated;\n- reuse HTTP/2 stream flow control/backpressure;\n- map the established stream into Runwire's WebSocket wire layer without creating a second TCP socket abstraction;\n- preserve independent cleanup from sibling streams.\n\nIts absence must not block core HTTP/2 request/response support.\n\n---\n\n## 10.12 HTTP/3 future boundary\n\nDesign the common HTTP transport and Webrick adapter so HTTP/3 can be added later without changing application semantics.\n\nDo not attempt to model HTTP/3 as merely another TCP frame codec. HTTP/3 requires QUIC/UDP/TLS 1.3 transport semantics, independent stream behavior and QPACK. It is intentionally outside the Runwire 1.0/Foundation 3 release gate.\n\nKeep public transport contracts version-neutral enough that a future HTTP/3 driver can normalize into the same Webrick-facing request/response model.'''
)

replace_once(
    'Runwire HTTP/1 connection/parser\n        ↓\nRunwire HTTP request transport object',
    'Runwire HTTP/1.1 connection or HTTP/2 connection/stream\n        ↓\nRunwire version-neutral HTTP request transport object'
)

replace_once(
    '- parser/protocol error;\n- backpressure transitions;',
    '- parser/protocol error;\n- HTTP/2 active streams / stream open-close-reset counts;\n- HTTP/2 GOAWAY / connection-vs-stream protocol failures;\n- HTTP/2 flow-control stalls and backpressure transitions;\n- HPACK decoded/compressed header bytes and bounded table size (without logging header values);\n- backpressure transitions;'
)

replace_once(
    '- no unbounded request headers/body buffering;\n- no process-global mutable runtime topology;',
    '- no unbounded request headers/body buffering;\n- no unbounded HTTP/2 stream/frame/header-block/HPACK state;\n- HTTP/2 stream/control-frame churn cannot create unbounded CPU or retained state;\n- no process-global mutable runtime topology;'
)

replace_once(
    '- header/body framing;\n- idle timeouts;',
    '- header/body framing;\n- HTTP/2 concurrent streams, stream churn and frame/control work budgets;\n- HTTP/2 compressed/decompressed header blocks and HPACK tables;\n- HTTP/2 per-stream + aggregate connection buffering/flow-control state;\n- idle timeouts;'
)

replace_once(
    '- body streaming rather than full buffering for large payloads;\n- cached header serialization only when immutable and measured useful;',
    '- body streaming rather than full buffering for large payloads;\n- HTTP/2 parser operates incrementally without whole-connection copies;\n- HTTP/2 stream scheduling prevents one stream from starving all siblings;\n- HPACK dynamic tables remain connection-local and bounded;\n- avoid per-frame object/allocation churn on the hottest paths where a simpler bounded representation benchmarks better;\n- cached header serialization only when immutable and measured useful;'
)

replace_once(
    '- keep-alive HTTP;\n- small dynamic Webrick route;',
    '- keep-alive HTTP/1.1;\n- multiplexed HTTP/2 where the comparator supports it;\n- small dynamic Webrick route over HTTP/1.1 and HTTP/2;'
)

replace_once(
    'Do not make public “faster than Workerman” claims unless repeatable measurements support them.',
    'Workerman remains the process/runtime and HTTP/1.x reference baseline. If the selected Workerman comparison build does not provide equivalent native HTTP/2 wire support, use a mature HTTP/2-capable host from the supported Runwire driver matrix (for example FrankenPHP, Swoole/OpenSwoole or RoadRunner where its front server exposes HTTP/2) as the protocol-level comparison rather than inventing a false Workerman HTTP/2 comparison.\n\nDo not make public “faster than Workerman” or HTTP/2 performance claims unless repeatable measurements support them.'
)

replace_once(
    '''## 34.3 HTTP tests\n\n- request line/header parsing;\n- duplicate headers;\n- Content-Length;\n- chunked request;\n- keep-alive;\n- connection close;\n- malformed headers;\n- conflicting body framing;\n- request-smuggling cases;\n- slowloris-style headers/body;\n- body limit;\n- streamed request body;\n- fixed/chunked/streamed response;\n- HEAD semantics at transport boundary coordinated with Webrick;\n- Webrick adapter parity against SAPI/Workerman adapters.''',
    '''## 34.3 HTTP tests\n\n### HTTP/1.1\n\n- request line/header parsing;\n- duplicate headers;\n- Content-Length;\n- chunked request;\n- keep-alive;\n- connection close;\n- malformed headers;\n- conflicting body framing;\n- request-smuggling cases;\n- slowloris-style headers/body;\n- body limit;\n- streamed request body;\n- fixed/chunked/streamed response;\n- HEAD semantics at transport boundary coordinated with Webrick.\n\n### HTTP/2\n\n- TLS ALPN chooses `h2` / `http/1.1` correctly;\n- connection preface;\n- SETTINGS + ACK validation;\n- all required frame parse/write paths;\n- fragmented frame reads/writes;\n- HEADERS + CONTINUATION assembly;\n- pseudo-header and lowercase-header validation;\n- HPACK indexed/literal/dynamic-table cases;\n- HPACK Huffman valid/invalid/bounded decode;\n- decompressed header-list hard ceiling;\n- concurrent stream state transitions;\n- stream-ID monotonicity/reuse failures;\n- connection + stream flow-control windows;\n- WINDOW_UPDATE errors/overflow;\n- per-stream and aggregate backpressure;\n- RST_STREAM cleanup;\n- GOAWAY graceful drain;\n- connection-level vs stream-level protocol errors;\n- rapid reset/open-close churn;\n- SETTINGS/PING/RST_STREAM/WINDOW_UPDATE flood budgets;\n- CONTINUATION/header-block flood limits;\n- slow body on one stream while sibling streams progress;\n- many slow readers with bounded worker memory;\n- HTTP/1.1 vs HTTP/2 parity through the same Webrick route/middleware/response semantics;\n- Webrick adapter parity against host/SAPI adapters where applicable.'''
)

replace_once(
    '- sustained keep-alive HTTP traffic;\n- connection churn;',
    '- sustained keep-alive HTTP/1.1 traffic;\n- sustained multiplexed HTTP/2 traffic with mixed stream lifetimes;\n- HTTP/2 reset/control-frame/header-block abuse under bounded policy;\n- connection churn;'
)

replace_once(
    'Runwire\\Protocol contract\nRunwire\\Supervisor',
    'Runwire\\Protocol contract\nRunwire\\Http\\ProtocolVersion / version-neutral HTTP transport contract\nRunwire\\Supervisor'
)

replace_once(
    '- native HTTP + Webrick integration;\n- Foundation 3 serving model;',
    '- native HTTP/1.1 + HTTP/2 + Webrick integration;\n- HTTP/2 ALPN, stream/flow-control/HPACK/security-limit tuning;\n- HTTP/2 graceful GOAWAY/drain and abuse-protection behavior;\n- Foundation 3 serving model;'
)

replace_once(
    '''1. Runwire process + supervisor primitives\n2. Runwire loop + TCP connection layer\n3. Runwire HTTP/1 transport\n4. Webrick RunwireRuntimeAdapter\n5. Foundation native serve integration\n6. Runwire/Omnibus process-supervision integration\n7. Pathwise/ReqShield boundary docs/tests alignment\n8. aggregate security + persistent-runtime acceptance\n9. performance comparison + tuning\n10. Runwire 1.0 release\n11. Foundation 3 final release acceptance''',
    '''1. Runwire process + supervisor primitives\n2. Runwire loop + TCP/TLS connection layer\n3. Version-neutral HTTP transport contract + HTTP/1.1 engine\n4. Webrick RunwireRuntimeAdapter against the common HTTP transport\n5. HTTP/2 frame/stream/HPACK/flow-control engine + TLS ALPN\n6. HTTP/1.1↔HTTP/2 Webrick parity + protocol abuse/fault acceptance\n7. Foundation native serve integration\n8. Runwire/Omnibus process-supervision integration\n9. Pathwise/ReqShield boundary docs/tests alignment\n10. aggregate security + persistent-runtime acceptance\n11. HTTP/1.1 + HTTP/2 performance comparison + tuning\n12. Runwire 1.0 release\n13. Foundation 3 final release acceptance'''
)

replace_once(
    '''supports_graceful_reload\nsupports_worker_recycle\nsupports_http2\nsupports_http3\nsupports_websocket''',
    '''supports_graceful_reload\nsupports_worker_recycle\nsupports_http1\nsupports_http2\nsupports_http3\nowns_http1_wire\nowns_http2_wire\nsupports_tls_alpn\nsupports_websocket'''
)

replace_once(
    '- HTTP/1.1 wire parser/serializer;\n- connection state/backpressure;',
    '- HTTP/1.1 wire parser/serializer;\n- HTTP/2 frame/stream/HPACK/flow-control engine;\n- TLS ALPN negotiation for `h2` / `http/1.1`;\n- connection state/backpressure across HTTP/1.1 and multiplexed HTTP/2;'
)

replace_once(
    '- transport/runtime capability snapshot clearly marks persistent application state as false for ordinary FPM request mode.',
    '- transport/runtime capability snapshot clearly marks persistent application state as false for ordinary FPM request mode;\n- if an upstream web server terminates HTTP/2 before FastCGI, `supports_http2` may describe end-to-end deployment capability while `owns_http2_wire` remains false for the FPM driver.'
)

replace_once(
    '- preserve host-owned threads/workers.',
    '- preserve host-owned threads/workers;\n- report HTTP/1.1/HTTP/2 capability separately from wire ownership; FrankenPHP may terminate HTTP/2 itself while Runwire adapts the resulting request rather than reparsing frames.'
)

replace_once(
    '- document and test persistent static/global state isolation.',
    '- document and test persistent static/global state isolation;\n- report HTTP/2 support/wire ownership truthfully according to the active Swoole/OpenSwoole server path instead of nesting the native Runwire HTTP/2 engine.'
)

replace_once(
    '- keep RoadRunner packages optional/suggested unless selected adapter code intrinsically requires a separate integration package.',
    '- keep RoadRunner packages optional/suggested unless selected adapter code intrinsically requires a separate integration package;\n- distinguish HTTP/2 accepted/terminated by the RoadRunner front server from Runwire native HTTP/2 wire ownership.'
)

replace_once(
    '- same application handler semantics across all available drivers;\n- startup/request/shutdown ordering;',
    '- same application handler semantics across all available drivers;\n- HTTP/1.1/HTTP/2 capability reporting distinguishes end-to-end support from Runwire wire ownership;\n- startup/request/shutdown ordering;'
)

replace_once(
    '- warm request throughput;\n- p50/p95/p99 latency;',
    '- warm request throughput over HTTP/1.1 and HTTP/2 where supported;\n- HTTP/2 multiplexing behavior at multiple concurrent-stream levels;\n- p50/p95/p99 latency;'
)

replace_once(
    '- [ ] native mode remains a complete first-party server implementation;',
    '- [ ] native mode remains a complete first-party HTTP/1.1 + HTTP/2 server implementation;\n- [ ] native TLS mode negotiates `h2` / `http/1.1` through ALPN;\n- [ ] host-driver capability reporting distinguishes `supports_http2` from `owns_http2_wire`;'
)

replace_once(
    '- [ ] HTTP/1.1 transport passes framing/smuggling/slow-client limits;\n- [ ] Webrick native Runwire adapter passes parity tests;',
    '- [ ] HTTP/1.1 transport passes framing/smuggling/slow-client limits;\n- [ ] HTTP/2 transport passes RFC 9113 framing/stream/SETTINGS/GOAWAY/flow-control acceptance;\n- [ ] HPACK is bounded, connection-local and passes malformed/Huffman/compression-amplification tests;\n- [ ] HTTP/2 Rapid Reset-style stream churn, control-frame floods and CONTINUATION/header-block abuse remain bounded;\n- [ ] multiplexed streams preserve independent backpressure, cancellation and Foundation request state;\n- [ ] native TLS ALPN negotiates HTTP/2/HTTP/1.1 correctly;\n- [ ] HTTP/1.1 and HTTP/2 requests have Webrick application-semantic parity;\n- [ ] Webrick native Runwire adapter passes parity tests;'
)

replace_once(
    '''TCP Listener + Connection + backpressure\n    ↓\nHTTP/1 transport\n    ↓\nWebrick adapter''',
    '''TCP/TLS Listener + Connection + backpressure\n    ↓\nVersion-neutral HTTP transport + HTTP/1.1\n    ↓\nWebrick adapter contract\n    ↓\nHTTP/2 frames + streams + HPACK + flow control + ALPN\n    ↓\nHTTP/1.1 / HTTP/2 parity + abuse acceptance'''
)

replace_once(
    '1. a supervised multi-worker TCP echo/HTTP fixture; and\n2. a structured bounded child command execution fixture.',
    '1. a supervised multi-worker TCP echo + HTTP/1.1 fixture;\n2. the same Webrick handler exercised over native HTTP/2 with multiplexed streams and bounded flow control; and\n3. a structured bounded child command execution fixture.'
)

# Remove any remaining launch wording that could imply HTTP/2 is optional.
text = text.replace('HTTP/2 is not required for Runwire 1.0/Foundation 3 launch', 'HTTP/2 is required for Runwire 1.0/Foundation 3 launch')

path.write_text(text)
