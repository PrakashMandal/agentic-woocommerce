"""Smoke test for the tool dispatcher — exercises every read-only tool
with a tool_use-shaped call, confirming each routes correctly and
returns JSON-serializable data.

update_stock is intentionally NOT exercised here — it's a write action;
test it deliberately, not as part of a routine smoke test.
"""

import json

from tools import dispatch_tool_call
from wc_client import WooCommerceClient

CALLS = [
    ("get_products", {"per_page": 5}),
    ("get_low_stock_products", {"threshold": 5}),
    ("get_orders", {"per_page": 5, "status": "completed"}),
    ("get_sales_trends", {"days": 30}),
]


def main() -> None:
    client = WooCommerceClient()

    for name, tool_input in CALLS:
        result = dispatch_tool_call(client, name, tool_input)
        print(f"\n=== {name}({tool_input}) ===")
        print(json.dumps(result, indent=2)[:1000])  # trimmed for readability
        print(f"-> {len(result)} item(s)")

    # get_order needs a real ID, so pull one from the orders we just fetched.
    orders = dispatch_tool_call(client, "get_orders", {"per_page": 1})
    if orders:
        order_id = orders[0]["id"]
        single = dispatch_tool_call(client, "get_order", {"order_id": order_id})
        print(f"\n=== get_order({{'order_id': {order_id}}}) ===")
        print(json.dumps(single, indent=2)[:1000])


if __name__ == "__main__":
    main()
