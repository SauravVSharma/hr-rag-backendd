import os

from langchain_community.document_loaders import PyPDFLoader
from langchain_text_splitters import RecursiveCharacterTextSplitter
from langchain_ollama import OllamaEmbeddings
from langchain_community.vectorstores import FAISS

documents = []

docs_folder = "docs"

for file in os.listdir(docs_folder):

    if file.endswith(".pdf"):

        path = os.path.join(docs_folder, file)

        try:

            print(f"Loading {file}")

            loader = PyPDFLoader(path)

            loaded_docs = loader.load()

            documents.extend(loaded_docs)

            print(f"SUCCESS: {file}")

        except Exception as e:

            print(f"FAILED: {file}")
            print(e)

print(f"Total docs loaded: {len(documents)}")

splitter = RecursiveCharacterTextSplitter(
    chunk_size=250,
    chunk_overlap=30
)

docs = splitter.split_documents(documents)

embeddings = OllamaEmbeddings(
    model="nomic-embed-text"
)

vectorstore = FAISS.from_documents(
    docs,
    embeddings
)

vectorstore.save_local("db")

print("Indexing complete!")