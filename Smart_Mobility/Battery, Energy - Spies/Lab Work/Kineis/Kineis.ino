#define ON_OFF_KIM_PIN 5

void setup() {
  pinMode(ON_OFF_KIM_PIN, OUTPUT);
  digitalWrite(ON_OFF_KIM_PIN, HIGH);
  delay(1500); 

  Serial.begin(115200);

  // --- TRY 4800 BAUD (Green Board) ---
  Serial1.begin(4800);
  delay(500);
  
  // Send reset / clear command
  Serial1.print("AT+LED=0,0\r\n");
  delay(1000);

  // Turn RED LED ON for 4 seconds
  Serial1.print("AT+LED=1,0\r\n");
  delay(4000);

  // Turn GREEN LED ON for 4 seconds
  Serial1.print("AT+LED=0,1\r\n");
  delay(4000);

  // Turn both OFF
  Serial1.print("AT+LED=0,0\r\n");
  delay(2000);

  // --- TRY 9600 BAUD (Black Board) ---
  Serial1.begin(9600);
  delay(500);

  // Send reset / clear command
  Serial1.print("AT+LED=0,0\r\n");
  delay(1000);

  // Turn RED LED ON for 4 seconds
  Serial1.print("AT+LED=1,0\r\n");
  delay(4000);

  // Turn GREEN LED ON for 4 seconds
  Serial1.print("AT+LED=0,1\r\n");
  delay(4000);

  // Turn both OFF
  Serial1.print("AT+LED=0,0\r\n");
}

void loop() {
}