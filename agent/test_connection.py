"""Smoke test — confirms the WooCommerce REST API keys and connectivity work."""

import time
from datetime import datetime

from wc_client import WooCommerceClient


def main() -> None:
    print(f"[{datetime.now().isoformat(timespec='seconds')}] Connecting...")

    client = WooCommerceClient()
    start = time.perf_counter()
    products = client.get_products(per_page=5)
    elapsed = time.perf_counter() - start

    print(f"Connected. Fetched {len(products)} products in {elapsed:.2f}s:")
    for p in products:
        print(f"  [{p.id}] {p.name} (SKU {p.sku}) — stock: {p.stock_quantity} ({p.stock_status})")


if __name__ == "__main__":
    main()