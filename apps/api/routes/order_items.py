from fastapi import APIRouter
from sqlalchemy import text
from apps.api.db import engine

router = APIRouter()


@router.get("/order-items")
def get_order_items():
    query = text("SELECT * FROM order_items")

    with engine.connect() as conn:
        result = conn.execute(query)
        items = [dict(row._mapping) for row in result]

    return items
