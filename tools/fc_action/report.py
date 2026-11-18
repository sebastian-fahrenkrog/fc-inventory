"""Console output: one table for all orders of a run."""
from rich.console import Console
from rich.table import Table

STYLES = {
    "updated": "green",
    "deactivated": "green",
    "no_update": "cyan",
    "not_active": "cyan",
    "major_only": "yellow",
    "rolled_back": "red",
    "failed": "red",
    "invalid": "red",
    "error": "red",
}


def _health(answer: dict) -> str:
    health = answer.get("health") or {}
    parts = []
    for phase in ("before", "after", "restored"):
        if phase in health:
            codes = "/".join(str(code or "-") for code in health[phase].values())
            parts.append(f"{phase} {codes}")
    return ", ".join(parts)


def _version(answer: dict) -> str:
    source, target = answer.get("from", ""), answer.get("to", "")
    if source and target and source != target:
        return f"{source} → {target}"
    offered = answer.get("offered", "")
    return f"{source} (offered: {offered})" if offered and offered != source else source


def results_table(site: str, answers: list[dict]) -> Table:
    table = Table(title=f"fc-action: {site}", title_justify="left")
    for column in ("Action", "Slug", "Result", "Version", "Health (home/login)", "Messages"):
        table.add_column(column, overflow="fold")
    for answer in answers:
        result = answer.get("result", "error")
        style = STYLES.get(result, "white")
        messages = "\n".join(str(m) for m in answer.get("messages", [])[-3:])
        table.add_row(answer.get("action", ""), answer.get("slug", ""), f"[{style}]{result}[/{style}]", _version(answer), _health(answer), messages)
    return table


def show(console: Console, site: str, answers: list[dict]) -> None:
    console.print(results_table(site, answers))
