from fastapi import APIRouter
from sqlalchemy import text
from pydantic import BaseModel
from apps.api.db import engine

router = APIRouter()


class UserCreate(BaseModel):
    name: str
    email: str


@router.post("/users")
def create_user(user: UserCreate):
    query = text("INSERT INTO users (name, email) VALUES (:name, :email)")

    with engine.connect() as conn:
        conn.execute(query, {"name": user.name, "email": user.email})
        conn.commit()

    return {"message": "User created"}


@router.get("/users")
def get_users():
    query = text("SELECT * FROM users")

    with engine.connect() as conn:
        result = conn.execute(query)
        users = [dict(row._mapping) for row in result]

    return users


@router.delete("/users/{user_id}")
def delete_user(user_id: int):
    query = text("DELETE FROM users WHERE id = :id")

    with engine.connect() as conn:
        conn.execute(query, {"id": user_id})
        conn.commit()

    return {"message": "User deleted"}
