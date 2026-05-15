from fastapi import FastAPI
from pydantic import BaseModel

from langchain_community.vectorstores import FAISS
from langchain_ollama import OllamaEmbeddings
from langchain_ollama import ChatOllama

app = FastAPI()

# Embedding model
embeddings = OllamaEmbeddings(
    model="nomic-embed-text"
)

# Load vector database
vectorstore = FAISS.load_local(
    "db",
    embeddings,
    allow_dangerous_deserialization=True
)

retriever = vectorstore.as_retriever()

# LLM
llm = ChatOllama(
    model="mistral"
)

class Query(BaseModel):
    question: str

@app.post("/ask")
def ask_question(query: Query):

    try:

        # Retrieve matching docs
        docs = retriever.invoke(query.question)

        context = "\n".join(
            [doc.page_content for doc in docs]
        )

        prompt = f"""
You are an HR assistant.

Answer ONLY from provided context.

Context:
{context}

Question:
{query.question}
"""

        response = llm.invoke(prompt)

        return {
            "answer": response.content
        }

    except Exception as e:

        return {
            "error": str(e)
        }