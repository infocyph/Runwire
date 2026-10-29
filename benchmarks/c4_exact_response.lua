
-- A real independent C-based HTTP generator. Inspect every actual response.
local peers = {}
function setup(thread)
  table.insert(peers, thread)
end
function init(args)
  invalid_responses = 0
end
function response(status, headers, body)
  if status ~= 200 or body ~= "ok" then
    invalid_responses = invalid_responses + 1
  end
end
function done(summary, latency, requests)
  local invalid = 0
  for _, thread in ipairs(peers) do
    invalid = invalid + (thread:get("invalid_responses") or 0)
  end
  local errors = summary.errors
  local io_errors = errors.connect + errors.read + errors.write + errors.status + errors.timeout
  io.write(string.format(
    'RUNWIRE_METRIC:{"requests_total":%d,"successful_requests":%d,"errors_total":%d,"validation_failures":%d,"timeouts_total":%d,"throughput_rps":%.3f,"latency_ms":{"p50":%.3f,"p95":%.3f,"p99":%.3f},"correctness_passed":%s}\n',
    summary.requests, summary.requests - invalid, io_errors, invalid, errors.timeout,
    summary.requests / summary.duration * 1000000,
    latency:percentile(50) / 1000,
    latency:percentile(95) / 1000,
    latency:percentile(99) / 1000,
    (invalid == 0 and io_errors == 0 and summary.requests > 0) and "true" or "false"
  ))
end
