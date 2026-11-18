"""Sends one signed order to a site and returns the parsed answer."""
import json
import logging
import time
import urllib.error
import urllib.request
from dataclasses import dataclass

from . import signing

log = logging.getLogger(__name__)
FAILED_RESULTS = {"failed", "rolled_back", "invalid"}


class OrderError(Exception):
    """The site did not accept or answer the order."""


@dataclass(frozen=True)
class Target:
    base_url: str
    key_id: str
    key: object
    timeout: int


def build_order(action: str, slug: str | None = None, allow_major: bool = False) -> bytes:
    """Compact JSON body; exactly these bytes are signed and sent."""
    order = {"schema": 1, "action": action}
    if slug is not None:
        order["slug"] = slug
    if allow_major:
        order["allow_major"] = True
    return json.dumps(order, separators=(",", ":")).encode()


def _post(url: str, body: bytes, headers: dict, timeout: int) -> tuple[int, bytes]:
    request = urllib.request.Request(url, data=body, method="POST", headers={"Content-Type": "application/json", **headers})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return response.status, response.read()
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read()
    except (urllib.error.URLError, TimeoutError) as exc:
        raise OrderError(f"no answer from {url}: {getattr(exc, 'reason', exc)}") from exc


def _attempt(target: Target, url: str, body: bytes) -> tuple[int, bytes]:
    """Every attempt gets its own timestamp and nonce; a nonce is valid once."""
    host = signing.signing_host(target.base_url)
    headers = signing.signed_headers(target.key, target.key_id, host, body, str(int(time.time())), signing.new_nonce())
    log.debug("POST %s (host in signature: %s)", url, host)
    return _post(url, body, headers, target.timeout)


def send(target: Target, body: bytes) -> dict:
    """Tries the /wp-json/ URL, then the ?rest_route= form (plain permalinks, blocked prefix)."""
    status, raw = _attempt(target, target.base_url + signing.PATH, body)
    if status == 404:
        log.debug("404 on the /wp-json/ URL, trying the rest_route form")
        status, raw = _attempt(target, f"{target.base_url}/?{signing.FALLBACK_QUERY}", body)
    if status == 404:
        raise OrderError(
            "404: the site rejected the order (plugin older than 1.1, no action key configured, "
            "wrong key id or key, clock off by more than 5 minutes)"
        )
    try:
        answer = json.loads(raw)
    except ValueError as exc:
        raise OrderError(f"HTTP {status} with a body that is not JSON") from exc
    if not isinstance(answer, dict) or "result" not in answer:
        raise OrderError(f"HTTP {status} without a result: {str(answer)[:200]}")
    return answer


def succeeded(answer: dict) -> bool:
    return answer.get("result") not in FAILED_RESULTS
