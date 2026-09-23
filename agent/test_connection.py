"""Smoke test — confirms the WooCommerce REST API keys and connectivity work."""

from wc_client import WooCommerceClient


def main() -> None:
    client = WooCommerceClient()
    products = client.get_products(per_page=5)
    print(f"Connected. Fetched {len(products)} products:")
    for p in products:
        print(f"  [{p.id}] {p.name} (SKU {p.sku}) — stock: {p.stock_quantity} ({p.stock_status})")


if __name__ == "__main__":
    main()