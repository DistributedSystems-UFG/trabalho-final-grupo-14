import time

class RoomAnalyzer:
    def __init__(self, energy_threshold=5.0):
        self.energy_threshold = energy_threshold

    def analyze(self, reading: dict) -> list:
        """
        Analyzes a room sensor reading and returns list of alerts if anomalies are found.
        Reading format: {
            'sala': '302',
            'energia': 2.4,
            'presenca': True,
            'luz': True,
            'timestamp': 1672531200
        }
        """
        alerts = []
        room = reading.get('sala', 'Unknown')
        energy = float(reading.get('energia', 0.0))
        presence = bool(reading.get('presenca', False))
        light = bool(reading.get('luz', False))
        timestamp = reading.get('timestamp', int(time.time()))

        # Rule 1: Room is empty but lights are left on (energy wastage)
        if not presence and light:
            alerts.append({
                'sala': room,
                'tipo': 'luz_acesa_vazia',
                'mensagem': f"ALERTA: Sala {room} está vazia, mas as luzes estão acesas!",
                'timestamp': timestamp
            })

        # Rule 2: Power consumption exceeds safety limit
        if energy > self.energy_threshold:
            alerts.append({
                'sala': room,
                'tipo': 'consumo_alto',
                'mensagem': f"ALERTA: Consumo elétrico na Sala {room} está crítico: {energy} kW (Limite: {self.energy_threshold} kW)!",
                'timestamp': timestamp
            })

        return alerts
