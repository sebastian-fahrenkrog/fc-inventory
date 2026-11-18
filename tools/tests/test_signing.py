"""The Python client must reproduce tests/vectors/action-v1.json byte for byte."""
import base64
import json
import unittest
from pathlib import Path

from fc_action import client, signing

VECTOR = json.loads((Path(__file__).resolve().parents[2] / "tests/vectors/action-v1.json").read_text())


class VectorTest(unittest.TestCase):
    def setUp(self):
        self.key = signing.key_from_seed(bytes.fromhex(VECTOR["seed_hex"]))

    def test_public_key(self):
        self.assertEqual(VECTOR["public_key"], signing.public_key_b64(self.key))

    def test_constants(self):
        self.assertEqual((VECTOR["prefix"], VECTOR["method"], VECTOR["path"]), (signing.PREFIX, signing.METHOD, signing.PATH))

    def test_message_and_signature(self):
        for case in VECTOR["cases"]:
            body = case["body"].encode()
            message = signing.action_message(VECTOR["host"], str(VECTOR["timestamp"]), case["nonce"], body)
            headers = signing.signed_headers(self.key, VECTOR["key_id"], VECTOR["host"], body, str(VECTOR["timestamp"]), case["nonce"])
            self.assertEqual(case["message"], message.decode(), case["name"])
            self.assertEqual(case["signature"], headers["X-FC-Signature"], case["name"])

    def test_order_bodies_match_the_vector(self):
        self.assertEqual(VECTOR["cases"][0]["body"].encode(), client.build_order("update_plugin", "akismet"))
        self.assertEqual(VECTOR["cases"][1]["body"].encode(), client.build_order("update_core", allow_major=True))


class HostTest(unittest.TestCase):
    def test_signing_host(self):
        cases = {
            "https://WWW.Example.com": "www.example.com",
            "https://www.example.com:443/": "www.example.com",
            "http://www.example.com:80": "www.example.com",
            "https://www.example.com:8443": "www.example.com:8443",
            "http://localhost:18081": "localhost:18081",
            "https://meisterbywürth.de": "xn--meisterbywrth-5ob.de",
        }
        for url, host in cases.items():
            self.assertEqual(host, signing.signing_host(url), url)

    def test_nonce_shape(self):
        nonce = signing.new_nonce()
        self.assertEqual(22, len(nonce))
        self.assertEqual(16, len(base64.urlsafe_b64decode(nonce + "==")))


if __name__ == "__main__":
    unittest.main()
