import os
import time
import json
import pika
import pymysql
from analyzer import RoomAnalyzer

# Load environment variables
RABBITMQ_HOST = os.getenv('RABBITMQ_HOST', '127.0.0.1')
RABBITMQ_PORT = int(os.getenv('RABBITMQ_PORT', 5672))
RABBITMQ_USER = os.getenv('RABBITMQ_USER', 'guest')
RABBITMQ_PASS = os.getenv('RABBITMQ_PASS', 'guest')
SENSOR_QUEUE = 'python_sensor_queue'
ALERT_QUEUE = 'alertas'

MYSQL_HOST = os.getenv('MYSQL_HOST', '127.0.0.1')
MYSQL_USER = os.getenv('MYSQL_USER', 'sb_user')
MYSQL_PASSWORD = os.getenv('MYSQL_PASSWORD', 'sb_password')
MYSQL_DB = os.getenv('MYSQL_DB', 'smartbuilding')

print("Starting Python Analytics Worker...")
analyzer = RoomAnalyzer(energy_threshold=5.0)

# Helper to connect to MySQL with retries
def connect_mysql():
    retries = 10
    while retries > 0:
        try:
            conn = pymysql.connect(
                host=MYSQL_HOST,
                user=MYSQL_USER,
                password=MYSQL_PASSWORD,
                database=MYSQL_DB,
                charset='utf8mb4',
                cursorclass=pymysql.cursors.DictCursor,
                autocommit=True
            )
            print("Successfully connected to MySQL Primary.")
            return conn
        except Exception as e:
            print(f"Waiting for MySQL Primary ({e})... {retries} retries left.")
            time.sleep(3)
            retries -= 1
    raise Exception("Could not connect to MySQL Primary")

# Helper to connect to RabbitMQ with retries
def connect_rabbitmq():
    retries = 10
    credentials = pika.PlainCredentials(RABBITMQ_USER, RABBITMQ_PASS)
    parameters = pika.ConnectionParameters(
        host=RABBITMQ_HOST,
        port=RABBITMQ_PORT,
        credentials=credentials,
        heartbeat=600,
        blocked_connection_timeout=300
    )
    while retries > 0:
        try:
            conn = pika.BlockingConnection(parameters)
            print("Successfully connected to RabbitMQ.")
            return conn
        except Exception as e:
            print(f"Waiting for RabbitMQ ({e})... {retries} retries left.")
            time.sleep(3)
            retries -= 1
    raise Exception("Could not connect to RabbitMQ")

def main():
    mysql_conn = connect_mysql()
    rabbit_conn = connect_rabbitmq()
    channel = rabbit_conn.channel()

    # Declare queues (ensure they exist)
    channel.queue_declare(queue=SENSOR_QUEUE, durable=True)
    channel.queue_declare(queue=ALERT_QUEUE, durable=True)
    
    # Fair dispatch (only 1 unacked message at a time to this worker)
    channel.basic_qos(prefetch_count=1)

    def callback(ch, method, properties, body):
        nonlocal mysql_conn
        try:
            payload = json.loads(body.decode('utf-8'))
        except Exception as e:
            print(f"Failed to parse JSON message: {e}")
            ch.basic_ack(delivery_tag=method.delivery_tag)
            return

        room = payload.get('sala')
        energy = payload.get('energia', 0.0)
        presence = payload.get('presenca', False)
        light = payload.get('luz', False)
        
        if not room:
            print("Message missing 'sala' field, discarding.")
            ch.basic_ack(delivery_tag=method.delivery_tag)
            return

        print(f"Received reading from Room {room} -> Energy: {energy}kW, Presence: {presence}, Light: {light}")

        # Persist to MySQL primary (re-connecting if connection died)
        db_persisted = False
        db_retries = 3
        while not db_persisted and db_retries > 0:
            try:
                with mysql_conn.cursor() as cursor:
                    sql = """
                        INSERT INTO historico_leituras (sala, energia, presenca, luz)
                        VALUES (%s, %s, %s, %s)
                    """
                    cursor.execute(sql, (room, float(energy), int(presence), int(light)))
                db_persisted = True
            except (pymysql.MySQLError, Exception) as e:
                print(f"Database error during insert: {e}. Reconnecting...")
                try:
                    mysql_conn.close()
                except:
                    pass
                try:
                    mysql_conn = connect_mysql()
                except:
                    pass
                db_retries -= 1
                time.sleep(1)

        # Critical: Only ACK the message if it was successfully saved to MySQL
        if not db_persisted:
            print(f"ERROR: Could not persist reading to MySQL. REQUEUING message for Room {room}.")
            ch.basic_nack(delivery_tag=method.delivery_tag, requeue=True)
            return

        # Perform real-time anomaly analysis
        alerts = analyzer.analyze(payload)
        for alert in alerts:
            print(f"Anomaly detected: {alert['mensagem']}")
            
            # Publish alert to RabbitMQ
            ch.basic_publish(
                exchange='',
                routing_key=ALERT_QUEUE,
                body=json.dumps(alert),
                properties=pika.BasicProperties(
                    delivery_mode=2 # persistent message
                )
            )

        # Finally, manual ACK
        ch.basic_ack(delivery_tag=method.delivery_tag)

    channel.basic_consume(queue=SENSOR_QUEUE, on_message_callback=callback)
    
    print("Worker is ready. Waiting for sensor readings...")
    try:
        channel.start_consuming()
    except KeyboardInterrupt:
        print("Stopping worker...")
        channel.stop_consuming()
    finally:
        try:
            rabbit_conn.close()
        except:
            pass
        try:
            mysql_conn.close()
        except:
            pass

if __name__ == '__main__':
    main()
