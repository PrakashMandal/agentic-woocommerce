"""
Tool layer — declares which WooCommerceClient methods are exposed to the
agent, and how a Claude tool_use call gets routed to the right method.

Two pieces, kept deliberately separate:
1. TOOLS — schema only (name, description, input_schema). This is what
   gets passed to the Claude API's `tools` parameter so the model knows
   what's available and how to call it. No logic lives here.
2. dispatch_tool_call() — the router, backed by TOOL_HANDLERS, a dict
   mapping tool name -> handler function. Takes what the model asked
   for (name + input dict) and calls the matching WooCommerceClient
   method, returning something JSON-serializable.

update_stock is a write action. No approval/guardrail logic lives here
on purpose — that belongs in the agent loop (a later step), not the
tool itself. A tool should stay dumb and mechanical.
"""

from __future__ import annotations

import dataclasses
from typing import Any, Callable

from wc_client import WooCommerceClient

TOOLS: list[dict[str, Any]] = [
    {
        "name": "get_products",
        "description": (
            "Get product details — name, SKU, price, stock status, and "
            "categories — regardless of stock status. Optionally filter "
            "by a text search or a category. Use get_low_stock_products "
            "instead for stock-specific questions."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "per_page": {
                    "type": "integer",
                    "description": "Max products to return.",
                    "default": 20,
                },
                "search": {
                    "type": "string",
                    "description": "Free-text search on product name/description.",
                },
                "category": {
                    "type": "string",
                    "description": "Category slug or ID to filter by.",
                },
            },
        },
    },
    {
        "name": "get_low_stock_products",
        "description": (
            "Get products that are in stock but at or below a given "
            "quantity threshold. Use this to answer questions about "
            "restocking, inventory risk, or which items are running low."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "threshold": {
                    "type": "integer",
                    "description": "Stock quantity at or below which a product counts as low stock.",
                    "default": 5,
                },
            },
        },
    },
    {
        "name": "get_orders",
        "description": "Get a list of orders, optionally filtered by status.",
        "input_schema": {
            "type": "object",
            "properties": {
                "per_page": {
                    "type": "integer",
                    "description": "Max orders to return.",
                    "default": 20,
                },
                "status": {
                    "type": "string",
                    "description": (
                        "Comma-separated order status(es) to filter by, "
                        "e.g. 'completed,processing'. Omit for all statuses."
                    ),
                },
            },
        },
    },
    {
        "name": "get_order",
        "description": "Get full details of a single order by its ID.",
        "input_schema": {
            "type": "object",
            "properties": {
                "order_id": {
                    "type": "integer",
                    "description": "The order's numeric ID.",
                },
            },
            "required": ["order_id"],
        },
    },
    {
        "name": "get_sales_trends",
        "description": (
            "Get daily revenue and order counts over a recent time window. "
            "Use this for questions about sales trends, busy/slow periods, "
            "or revenue over time. Only counts completed/processing orders."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "days": {
                    "type": "integer",
                    "description": "How many days back to include.",
                    "default": 30,
                },
            },
        },
    },
    {
        "name": "update_stock",
        "description": (
            "Set the stock quantity for a product, or a specific variation "
            "of a variable product. This is a WRITE action — it changes "
            "real store data."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "product_id": {
                    "type": "integer",
                    "description": "The product's numeric ID.",
                },
                "stock_quantity": {
                    "type": "integer",
                    "description": "New stock quantity to set.",
                },
                "variation_id": {
                    "type": "integer",
                    "description": "If updating one variation of a variable product, its ID.",
                },
            },
            "required": ["product_id", "stock_quantity"],
        },
    },
]


def _handle_get_products(client: WooCommerceClient, tool_input: dict[str, Any]) -> Any:
    kwargs: dict[str, Any] = {"per_page": tool_input.get("per_page", 20)}
    if "search" in tool_input:
        kwargs["search"] = tool_input["search"]
    if "category" in tool_input:
        kwargs["category"] = tool_input["category"]
    return [dataclasses.asdict(p) for p in client.get_products(**kwargs)]


def _handle_get_low_stock_products(client: WooCommerceClient, tool_input: dict[str, Any]) -> Any:
    threshold = tool_input.get("threshold", 5)
    return [dataclasses.asdict(p) for p in client.get_low_stock_products(threshold=threshold)]


def _handle_get_orders(client: WooCommerceClient, tool_input: dict[str, Any]) -> Any:
    kwargs: dict[str, Any] = {"per_page": tool_input.get("per_page", 20)}
    if "status" in tool_input:
        kwargs["status"] = tool_input["status"]
    return [dataclasses.asdict(o) for o in client.get_orders(**kwargs)]


def _handle_get_order(client: WooCommerceClient, tool_input: dict[str, Any]) -> Any:
    return dataclasses.asdict(client.get_order(tool_input["order_id"]))


def _handle_get_sales_trends(client: WooCommerceClient, tool_input: dict[str, Any]) -> Any:
    days = tool_input.get("days", 30)
    return [dataclasses.asdict(t) for t in client.get_sales_trends(days=days)]


def _handle_update_stock(client: WooCommerceClient, tool_input: dict[str, Any]) -> Any:
    return client.update_stock(
        product_id=tool_input["product_id"],
        stock_quantity=tool_input["stock_quantity"],
        variation_id=tool_input.get("variation_id"),
    )


TOOL_HANDLERS: dict[str, Callable[[WooCommerceClient, dict[str, Any]], Any]] = {
    "get_products": _handle_get_products,
    "get_low_stock_products": _handle_get_low_stock_products,
    "get_orders": _handle_get_orders,
    "get_order": _handle_get_order,
    "get_sales_trends": _handle_get_sales_trends,
    "update_stock": _handle_update_stock,
}


def dispatch_tool_call(client: WooCommerceClient, name: str, tool_input: dict[str, Any]) -> Any:
    """Route a tool_use call (from the Claude API) to the matching handler."""
    handler = TOOL_HANDLERS.get(name)
    if handler is None:
        raise ValueError(f"Unknown tool: {name}")
    return handler(client, tool_input)
