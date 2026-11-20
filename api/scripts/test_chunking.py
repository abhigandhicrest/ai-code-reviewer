import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from app.llm.chunking import chunk_budget_tokens, split_code_into_chunks

small = "def hi():\n    return 1\n"
chunks = split_code_into_chunks(small, max_chunk_tokens=1000)
assert len(chunks) == 1

big = "\n".join([f"line_{i} = {i}  # sample" for i in range(5000)])
budget = chunk_budget_tokens(8192, 1024, 450)
chunks = split_code_into_chunks(big, max_chunk_tokens=budget, max_chunks=6)
assert 1 < len(chunks) <= 6
assert chunks[0].start_line == 1
print("chunking_ok", len(chunks), "budget", budget)
