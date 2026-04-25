from fastapi import APIRouter
from sqlalchemy import text
from apps.api.db import engine

router = APIRouter()


@router.get("/analytics/today")
def today_orders():
    query = text("""
        SELECT oi.*
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE DATE(o.created_at) = CURDATE()
    """)

    with engine.connect() as conn:
        result = conn.execute(query)
        items = [dict(row._mapping) for row in result]

    return items
