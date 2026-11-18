"""Command line: argument parsing and the flow of one run."""
import argparse
import logging
import sys
from pathlib import Path

from rich.console import Console
from rich.logging import RichHandler
from rich.progress import Progress, SpinnerColumn, TextColumn, TimeElapsedColumn

from . import client, config, report, signing

log = logging.getLogger("fc_action")
ACTIONS = {"update-plugin": "update_plugin", "update-theme": "update_theme", "deactivate-plugin": "deactivate_plugin"}


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="fc-action", description="Sign and send orders to the fc-inventory plugin of a WordPress site.")
    parser.add_argument("--config", help=f"configuration file (default: ${config.ENV_VAR} or {config.DEFAULT_PATH})")
    parser.add_argument("-v", "--verbose", action="store_true", help="debug logging")
    commands = parser.add_subparsers(dest="command", required=True)

    keygen = commands.add_parser("keygen", help="create an action key pair")
    keygen.add_argument("--out", required=True, help="file for the private key (must not exist)")
    keygen.add_argument("--key-id", required=True, help="key id for FC_INVENTORY_ACTION_KEYS")

    for name, text in (("update-plugin", "update plugins to the offered version"), ("update-theme", "update themes to the offered version"), ("deactivate-plugin", "deactivate plugins")):
        sub = commands.add_parser(name, help=text)
        sub.add_argument("site", help="site name from the configuration")
        sub.add_argument("slugs", nargs="+", metavar="slug", help="directory name as in the inventory")

    core = commands.add_parser("update-core", help="update WordPress core")
    core.add_argument("site", help="site name from the configuration")
    core.add_argument("--allow-major", action="store_true", help="also move to a newer x.y branch")
    return parser


def run_keygen(args: argparse.Namespace, console: Console) -> int:
    path = Path(args.out).expanduser()
    try:
        key = signing.write_new_key(path)
    except FileExistsError:
        log.error("%s exists; an existing key is never overwritten", path)
        return 1
    console.print(f"Private key written to {path} (mode 600).")
    console.print("Line for wp-config.php (above the 'stop editing' comment):")
    console.print(f"define( 'FC_INVENTORY_ACTION_KEYS', array( '{args.key_id}' => '{signing.public_key_b64(key)}' ) );", markup=False, soft_wrap=True)
    return 0


def orders_for(args: argparse.Namespace) -> list[bytes]:
    if args.command == "update-core":
        return [client.build_order("update_core", allow_major=args.allow_major)]
    return [client.build_order(ACTIONS[args.command], slug) for slug in args.slugs]


def send_all(target: client.Target, orders: list[bytes], console: Console) -> list[dict]:
    """One order per request, in sequence; a transport error ends the run."""
    answers = []
    columns = (SpinnerColumn(), TextColumn("{task.description}"), TimeElapsedColumn())
    with Progress(*columns, console=console, transient=True) as progress:
        for body in orders:
            task = progress.add_task(body.decode(), total=None)
            try:
                answers.append(client.send(target, body))
            except client.OrderError as exc:
                log.error("%s", exc)
                answers.append({"action": "", "slug": body.decode(), "result": "error", "messages": [str(exc)]})
                break
            finally:
                progress.remove_task(task)
    return answers


def run_orders(args: argparse.Namespace, console: Console) -> int:
    cfg = config.load(config.config_path(args.config))
    target = client.Target(cfg.site_url(args.site), cfg.key_id, signing.load_key(cfg.key_file), cfg.timeout)
    answers = send_all(target, orders_for(args), console)
    report.show(console, args.site, answers)
    return 0 if all(client.succeeded(a) and a.get("result") != "error" for a in answers) else 1


def main() -> None:
    args = build_parser().parse_args()
    console = Console()
    logging.basicConfig(level=logging.DEBUG if args.verbose else logging.INFO, format="%(message)s", handlers=[RichHandler(console=console, show_path=False)])
    try:
        code = run_keygen(args, console) if args.command == "keygen" else run_orders(args, console)
    except (config.ConfigError, signing.KeyFileError, ValueError) as exc:
        log.error("%s", exc)
        code = 1
    sys.exit(code)
