"""
WooCommerceClient — thin wrapper around the official `woocommerce` package.

Why not raw requests.Session + Basic Auth (like CodaClient)?
WooCommerce's REST API requires OAuth 1.0a "one-legged" signing for any
plain-HTTP request — Basic Auth only works over HTTPS. Our local store
is http://localhost:8080, so hand-rolling this means implementing
HMAC-SHA1 request signing ourselves. The `woocommerce` package already
does this correctly, so we wrap it instead of reinventing OAuth1.
"""

from __future__ import annotations

import os
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
from typing import Any

from dotenv import load_dotenv
from woocommerce import API

load_dotenv()


@dataclass
class Product:
    id: int
    name: str
    sku: str
    price: str
    stock_quantity: int | None
    stock_status: str
    categories: list[str] = field(default_factory=list)


@dataclass
class Order:
    id: int
    status: str
    total: str
    date_created: str
    customer_id: int
    line_items: list[dict[str, Any]] = field(default_factory=list)


@dataclass
class SalesTrendPoint:
    period: str  # 'YYYY-MM-DD'
    order_count: int
    revenue: float


class WooCommerceClient:
    """Wraps the WooCommerce REST API v3 for the agent's tools."""

    def __init__(self) -> None:
        self._api = API(
            url=os.environ["WC_STORE_URL"],
            consumer_key=os.environ["WC_CONSUMER_KEY"],
            consumer_secret=os.environ["WC_CONSUMER_SECRET"],
            version="wc/v3",
            timeout=15,
        )

    # ---- products ----
    def get_products(self, per_page: int = 20, **params: Any) -> list[Product]:
        response = self._api.get("products", params={"per_page": per_page, **params})
        response.raise_for_status()
        return [self._to_product(item) for item in response.json()]

    def get_low_stock_products(self, threshold: int = 5) -> list[Product]:
        # WooCommerce has no "below threshold" filter, so pull in-stock
        # managed-stock products and filter client-side.
        products = self.get_products(per_page=100, stock_status="instock")
        return [
            p
            for p in products
            if p.stock_quantity is not None and p.stock_quantity <= threshold
        ]

    # ---- orders ----
    def get_orders(self, per_page: int = 20, **params: Any) -> list[Order]:
        response = self._api.get("orders", params={"per_page": per_page, **params})
        response.raise_for_status()
        return [self._to_order(item) for item in response.json()]

    def get_order(self, order_id: int) -> Order:
        response = self._api.get(f"orders/{order_id}")
        response.raise_for_status()
        return self._to_order(response.json())

    def get_sales_trends(
        self, days: int = 30, statuses: list[str] | None = None
    ) -> list[SalesTrendPoint]:
        """Revenue and order count per day, over the last `days` days.

        Counts only statuses that represent a real sale (completed,
        processing by default) — cancelled/failed/refunded orders would
        otherwise inflate apparent revenue.
        """
        statuses = statuses or ["completed", "processing"]
        after = (datetime.now(timezone.utc) - timedelta(days=days)).isoformat()

        orders: list[Order] = []
        page = 1
        while True:
            batch = self.get_orders(
                per_page=100, page=page, after=after, status=",".join(statuses)
            )
            if not batch:
                break
            orders.extend(batch)
            if len(batch) < 100:
                break
            page += 1

        buckets: dict[str, list[float]] = {}
        for order in orders:
            day = order.date_created[:10]  # 'YYYY-MM-DDT...' -> 'YYYY-MM-DD'
            buckets.setdefault(day, []).append(float(order.total or 0))

        return [
            SalesTrendPoint(period=day, order_count=len(totals), revenue=round(sum(totals), 2))
            for day, totals in sorted(buckets.items())
        ]

    # ---- writes ----
    def update_stock(
        self, product_id: int, stock_quantity: int, variation_id: int | None = None
    ) -> dict[str, Any]:
        """Set stock quantity for a product or a specific variation.

        No approval/guardrail logic here on purpose — that belongs in the
        agent loop, not the tool itself.
        """
        endpoint = (
            f"products/{product_id}/variations/{variation_id}"
            if variation_id
            else f"products/{product_id}"
        )
        response = self._api.put(endpoint, {"stock_quantity": stock_quantity, "manage_stock": True})
        response.raise_for_status()
        return response.json()

    # ---- mappers ----
    @staticmethod
    def _to_product(data: dict[str, Any]) -> Product:
        return Product(
            id=data["id"],
            name=data["name"],
            sku=data.get("sku", ""),
            price=data.get("price", ""),
            stock_quantity=data.get("stock_quantity"),
            stock_status=data.get("stock_status", ""),
            categories=[c["name"] for c in data.get("categories", [])],
        )

    @staticmethod
    def _to_order(data: dict[str, Any]) -> Order:
        return Order(
            id=data["id"],
            status=data["status"],
            total=data.get("total", ""),
            date_created=data.get("date_created", ""),
            customer_id=data.get("customer_id", 0),
            line_items=data.get("line_items", []),
        )
