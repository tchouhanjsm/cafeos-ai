from fastapi import FastAPI
from apps.api.routes.users import router as users_router
from apps.api.routes.order_items import router as order_items_router
from apps.api.routes.analytics import router as analytics_router
from apps.api.routes.dashboard import router as dashboard_router

app = FastAPI()

app.include_router(users_router)
app.include_router(order_items_router)
app.include_router(analytics_router)
app.include_router(dashboard_router)


@app.get("/")
def root():
    return {"status": "ok"}
