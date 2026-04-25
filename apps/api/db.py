from sqlalchemy import create_engine

DATABASE_URL = "mysql+pymysql://root:@localhost:3306/cafeos"

engine = create_engine(DATABASE_URL)
