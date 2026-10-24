TLS EOF policy
==============

PHP OpenSSL streams do not reliably report authenticated close_notify on EOF.
Runwire's outbound TLS connections classify bare EOF as
TLS_UNVERIFIED_EOF, never as verified clean application EOF.
This fail-closed classification can also reject legitimate TLS shutdowns whose
close-notify is not observable through PHP streams. Applications must enforce
message completeness using protocol framing, not read-until-EOF.

Raw TCP shutdownWrite is rejected on encrypted connections because a TCP
half-close is not a TLS close-notify. Inbound server TLS behavior is unchanged.
This is a conservative truncation-defense policy, not proof that a remote
peer truncated ciphertext. Authenticated close-notify recognition requires
further low-level transport support and certification.
