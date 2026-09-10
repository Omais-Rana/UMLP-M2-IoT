#include <SPI.h>
#include <RH_RF95.h>

#define RFM95_CS 8
#define RFM95_RST 4
#define RFM95_INT 3
#define LED_PIN LED_BUILTIN

RH_RF95 rf95(RFM95_CS, RFM95_INT);

uint8_t buf[255];
int i = 0;

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

  if (!rf95.init()) {
    while (1);
  }

  rf95.setModemConfig(RH_RF95::Bw125Cr45Sf128);
  rf95.setFrequency(868.1);
  rf95.setTxPower(8, false);
}

void loop() {
  String message = "Xeno's Solar #";
  message += i;
  i++;

  message.toCharArray((char*)buf, message.length() + 1);

  digitalWrite(LED_PIN, HIGH);
  delay(100);
  
  rf95.send((uint8_t *)buf, message.length() + 1);
  rf95.waitPacketSent();
  
  digitalWrite(LED_PIN, LOW);
  
  delay(3000);
}