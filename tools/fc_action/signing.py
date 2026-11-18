"""Contract action-v1: key handling and the signed message. No I/O besides key files."""
import base64
import hashlib
import os
import secrets
from pathlib import Path
from urllib.parse import urlsplit

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

PREFIX = "fc-inventory-action-v1"
METHOD = "POST"
PATH = "/wp-json/fc-inventory/v1/action"
FALLBACK_QUERY = "rest_route=/fc-inventory/v1/action"
DEFAULT_PORTS = {"https": 443, "http": 80}


class KeyFileError(Exception):
    """The key file is missing, unreadable or not a 32-byte seed."""


def new_nonce() -> str:
    """16 random bytes, base64url without padding (22 characters)."""
    return base64.urlsafe_b64encode(secrets.token_bytes(16)).rstrip(b"=").decode()


def signing_host(base_url: str) -> str:
    """Host as the plugin sees it: lowercase, punycode, port only if not the default."""
    parts = urlsplit(base_url)
    host = (parts.hostname or "").encode("idna").decode().lower()
    if not host:
        raise ValueError(f"no host in URL {base_url!r}")
    if parts.port and parts.port != DEFAULT_PORTS.get(parts.scheme):
        return f"{host}:{parts.port}"
    return host


def action_message(host: str, timestamp: str, nonce: str, body: bytes) -> bytes:
    """Fields joined by newline, no trailing newline; the body enters as its SHA-256 hex."""
    fields = [PREFIX, METHOD, host, PATH, timestamp, nonce, hashlib.sha256(body).hexdigest()]
    return "\n".join(fields).encode()


def key_from_seed(seed: bytes) -> Ed25519PrivateKey:
    if len(seed) != 32:
        raise KeyFileError("the key must be a 32-byte seed")
    return Ed25519PrivateKey.from_private_bytes(seed)


def load_key(path: Path) -> Ed25519PrivateKey:
    """Reads a base64 seed; refuses files other users can read."""
    try:
        mode = path.stat().st_mode & 0o777
        text = path.read_text().strip()
    except OSError as exc:
        raise KeyFileError(f"cannot read key file {path}: {exc.strerror}") from exc
    if mode & 0o077:
        raise KeyFileError(f"key file {path} has mode {mode:o}; it must be 600")
    try:
        return key_from_seed(base64.b64decode(text, validate=True))
    except ValueError as exc:
        raise KeyFileError(f"key file {path} is not valid base64") from exc


def public_key_b64(key: Ed25519PrivateKey) -> str:
    raw = key.public_key().public_bytes(serialization.Encoding.Raw, serialization.PublicFormat.Raw)
    return base64.b64encode(raw).decode()


def write_new_key(path: Path) -> Ed25519PrivateKey:
    """Creates the key file with mode 0600; never overwrites an existing file."""
    seed = secrets.token_bytes(32)
    path.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "w") as handle:
        handle.write(base64.b64encode(seed).decode() + "\n")
    return key_from_seed(seed)


def signed_headers(key: Ed25519PrivateKey, key_id: str, host: str, body: bytes, timestamp: str, nonce: str) -> dict:
    signature = key.sign(action_message(host, timestamp, nonce, body))
    return {
        "X-FC-Key-Id": key_id,
        "X-FC-Timestamp": timestamp,
        "X-FC-Nonce": nonce,
        "X-FC-Signature": base64.b64encode(signature).decode(),
    }
