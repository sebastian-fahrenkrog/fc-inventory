"""Loads and validates the YAML configuration."""
import os
from dataclasses import dataclass
from pathlib import Path

import yaml

DEFAULT_PATH = "~/.config/fc-action/sites.yml"
ENV_VAR = "FC_ACTION_CONFIG"
KNOWN_KEYS = {"key_id", "key_file", "timeout", "sites"}


class ConfigError(Exception):
    """The configuration file is missing or invalid."""


@dataclass(frozen=True)
class Config:
    key_id: str
    key_file: Path
    timeout: int
    sites: dict

    def site_url(self, name: str) -> str:
        if name not in self.sites:
            known = ", ".join(sorted(self.sites)) or "none"
            raise ConfigError(f"unknown site {name!r} (configured: {known})")
        return self.sites[name].rstrip("/")


def config_path(cli_value: str | None) -> Path:
    return Path(cli_value or os.environ.get(ENV_VAR) or DEFAULT_PATH).expanduser()


def _read(path: Path) -> dict:
    try:
        data = yaml.safe_load(path.read_text())
    except OSError as exc:
        raise ConfigError(f"cannot read {path}: {exc.strerror}") from exc
    except yaml.YAMLError as exc:
        raise ConfigError(f"{path} is not valid YAML: {exc}") from exc
    if not isinstance(data, dict):
        raise ConfigError(f"{path} must contain a mapping")
    return data


def _sites(raw: object, path: Path) -> dict:
    if not isinstance(raw, dict) or not raw:
        raise ConfigError(f"{path}: 'sites' must map names to URLs")
    for name, url in raw.items():
        if not isinstance(url, str) or not url.startswith(("https://", "http://")):
            raise ConfigError(f"{path}: site {name!r} needs a URL with scheme")
    return {str(name): url for name, url in raw.items()}


def load(path: Path) -> Config:
    data = _read(path)
    unknown = set(data) - KNOWN_KEYS
    if unknown:
        raise ConfigError(f"{path}: unknown key(s): {', '.join(sorted(unknown))}")
    for key in ("key_id", "key_file"):
        if not isinstance(data.get(key), str) or not data[key]:
            raise ConfigError(f"{path}: '{key}' is missing")
    timeout = data.get("timeout", 900)
    if not isinstance(timeout, int) or timeout < 1:
        raise ConfigError(f"{path}: 'timeout' must be a positive number of seconds")
    return Config(data["key_id"], Path(data["key_file"]).expanduser(), timeout, _sites(data.get("sites"), path))
