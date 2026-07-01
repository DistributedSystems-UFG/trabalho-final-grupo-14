FROM python:3.9-slim

WORKDIR /app

# Copy requirements and install dependencies
COPY requirements.txt /app/
RUN pip install --no-cache-dir -r requirements.txt

# Copy worker application code
COPY . /app/

# Run python unbuffered to allow real-time logs in docker-compose
CMD ["python", "-u", "worker.py"]
