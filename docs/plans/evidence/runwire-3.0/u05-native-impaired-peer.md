# U05 native independent post-handshake UDP impairment — rolling evidence

- Candidate W141: 206a90aec854432ff301eb3d262b1de9e429eaae.
- Independent real-peer job: https://github.com/infocyph/Runwire/actions/runs/40185537136 .
- The PHP 8.5 step named 'Recover native QUIC sibling streams under real independent UDP loss and reorder' PASSED in inspected step results.
- Peer reports actual positive dropped and delayed packet counts after completed TLS negotiation; worker verifies six native resets, two fresh echo siblings and uni FIN.
- W141 full Security & Standards quality run had unrelated autoscale Pint failure; W142 addresses it and adds peer Python syntax verification.
- Remaining U05.05/U05.09/U05.10 gates: sustained stalled peers, cancellation storm ownership, full bidirectional loss/reorder and latency/RSS certification.
- This document does not certify release readiness or change accepted checkboxes.
