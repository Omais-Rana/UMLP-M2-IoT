#include <SPI.h>
#include <RH_RF95.h>

#define RFM95_CS 8
#define RFM95_RST 4
#define RFM95_INT 3
#define LED_PIN LED_BUILTIN

RH_RF95 rf95(RFM95_CS, RFM95_INT);

// Dynamically change the parameters here
#define TX_POWER 8         
#define SPREADING_FACTOR 7
#define PAYLOAD_SIZE 256     

void setup() {
  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, LOW);

  pinMode(RFM95_RST, OUTPUT);
  digitalWrite(RFM95_RST, HIGH);
  delay(10);
  digitalWrite(RFM95_RST, LOW);
  delay(10);
  digitalWrite(RFM95_RST, HIGH);
  delay(10);

  Serial.begin(115200);
  while (!Serial);

  if (!rf95.init()) {
    Serial.println("LoRa radio init failed");
    while (1);
  }

  rf95.setFrequency(868.1);
  
  rf95.setTxPower(TX_POWER, false);

  rf95.setSpreadingFactor(SPREADING_FACTOR);
}

void loop() {
  uint8_t payload[PAYLOAD_SIZE];
  memset(payload, 'A', sizeof(payload) - 1);
  payload[PAYLOAD_SIZE - 1] = '\0';

  // Turn LED ON right before sending to measure exact transmission time
  digitalWrite(LED_PIN, HIGH);
  
  rf95.send(payload, sizeof(payload));
  rf95.waitPacketSent();
  
  digitalWrite(LED_PIN, LOW);

  Serial.print("Sent packet size: ");
  Serial.print(PAYLOAD_SIZE);
  Serial.print(" bytes | SF: ");
  Serial.print(SPREADING_FACTOR);
  Serial.print(" | Power: ");
  Serial.println(TX_POWER);

  delay(3000);
}