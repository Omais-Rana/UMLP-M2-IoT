/**
 * @file idAssignCode.cpp
 **/
#include "idAssignCode.hpp"
#include <iostream>
#include <set>
#include <cmath>
#include <chrono>
#include <random>
#include <cstdio>

using namespace std;

static long g_msgs[6] = {0};   // messages sent per type
static set<int> g_ids;
static bool g_duplicate = false;
static int  g_N = 0, g_bits = 0, g_maxId = -1;
using RealClock = std::chrono::steady_clock;
static RealClock::time_point g_start, g_t12;
static int g_candidates = 0, g_pick = -1;
static int g_leaderId = -1; static std::string g_leaderPos;
static int g_lastId = -1;   static std::string g_lastPos;      // last module reached in phase 3
static double g_t12Ms = -1, g_totalMs = -1;

namespace IdAssign {

IdAssignCode::IdAssignCode(BlinkyBlocksBlock *host) : BlinkyBlocksBlockCode(host), module(host) {
  if (not host) return;
  addMessageEventFunc2(MSG_EXPLORE, std::bind(&IdAssignCode::onExplore, this, std::placeholders::_1, std::placeholders::_2));
  addMessageEventFunc2(MSG_CONFIRM, std::bind(&IdAssignCode::onConfirm, this, std::placeholders::_1, std::placeholders::_2));
  addMessageEventFunc2(MSG_DECLINE, std::bind(&IdAssignCode::onDecline, this, std::placeholders::_1, std::placeholders::_2));
  addMessageEventFunc2(MSG_REPORT,  std::bind(&IdAssignCode::onReport,  this, std::placeholders::_1, std::placeholders::_2));
  addMessageEventFunc2(MSG_ASSIGN,  std::bind(&IdAssignCode::onAssign,  this, std::placeholders::_1, std::placeholders::_2));
}

void IdAssignCode::parseUserBlockElements(TiXmlElement *config) {
  const char *attr = config->Attribute("isLeader");
  isLeader = (attr ? Simulator::extractBoolFromString(attr) : false);
  const char *r = config->Attribute("randomLeader");
  if (r && Simulator::extractBoolFromString(r)) candidateIdx = g_candidates++;
}

std::string IdAssignCode::onInterfaceDraw() {
  using std::to_string;
  std::string str = "Module " + to_string(getId());
  str += isLeader ? " (LEADER)" : "";
  str += "\nunique ID: " + (uniqueId >= 0 ? to_string(uniqueId) : std::string("-"));
  str += "\nsub-tree size: " + to_string(subtreeSize) + ", children: " + to_string(children.size());
  if (g_leaderId >= 0) str += "\nleader: module " + to_string(g_leaderId) + " at " + g_leaderPos;
  if (g_lastId >= 0) str += "\nlast reached: module " + to_string(g_lastId) + " at " + g_lastPos;
  if (g_totalMs >= 0) {
    long total = 0; for (int t = 1; t <= 5; t++) total += g_msgs[t];
    char buf[200];
    snprintf(buf, sizeof(buf), "\n<<< SYSTEM >>>\nN = %d, ID length = %d bits\nREAL time ph.1+2: %.3f ms\nREAL time total: %.3f ms",
             g_N, g_bits, g_t12Ms, g_totalMs);
    str += buf;
    str += "\nmessages: " + to_string(total) + " (t1=" + to_string(g_msgs[1]) + " t2=" + to_string(g_msgs[2]) +
           " t3=" + to_string(g_msgs[3]) + " t4=" + to_string(g_msgs[4]) + " t5=" + to_string(g_msgs[5]) + ")";
    str += std::string("\nIDs unique: ") + (g_duplicate ? "NO" : "yes");
  } else {
    str += "\n(algorithm running / not started)";
  }
  return str;
}

void IdAssignCode::startup() {
  if (candidateIdx >= 0) {                                    // draw ONE random leader per run
    if (g_pick < 0) {
      std::random_device rd;
      std::mt19937 gen(rd() ^ (unsigned)std::chrono::steady_clock::now().time_since_epoch().count());
      g_pick = (int)(gen() % g_candidates);
    }
    isLeader = (candidateIdx == g_pick);
  }
  if (isLeader) {
    g_leaderId = getId();
    g_leaderPos = module->position.to_string();
    std::cout << "LEADER ELECTED: module " << g_leaderId << " at " << g_leaderPos << " (" << g_candidates << " candidates)" << std::endl;
    g_start = RealClock::now();
    discovered = true;
    setColor(WHITE);
    explore(nullptr);
    check();
  }
}

// send one message of a given type
void IdAssignCode::sendOne(int type, P2PNetworkInterface *itf, int value) {
  g_msgs[type - 1000]++;
  if (type == MSG_REPORT || type == MSG_ASSIGN)
    sendMessage("m", new MessageOf<int>(type, value), itf, 1000, 100);
  else
    sendMessage("m", new Message(type), itf, 1000, 100);
}

// Phase 1: type 1 to all neighbours except 'except'
void IdAssignCode::explore(P2PNetworkInterface *except) {
  int n;
  if (except) n = sendMessageToAllNeighbors("explore", new Message(MSG_EXPLORE), 1000, 100, 1, except);
  else        n = sendMessageToAllNeighbors("explore", new Message(MSG_EXPLORE), 1000, 100, 0);
  g_msgs[1] += n;
  pending += n;
}

void IdAssignCode::onExplore(std::shared_ptr<Message> _msg, P2PNetworkInterface *sender) {
  if (discovered) {
    sendOne(MSG_DECLINE, sender, 0);              // type 3
  } else {
    discovered = true;
    parent = sender;
    sendOne(MSG_CONFIRM, sender, 0);              // type 2
    explore(sender);
    check();                                      // leaf
  }
}

void IdAssignCode::onConfirm(std::shared_ptr<Message> _msg, P2PNetworkInterface *sender) {
  pending--; reportsMissing++;
  children.push_back({sender, 0});
  check();
}

void IdAssignCode::onDecline(std::shared_ptr<Message> _msg, P2PNetworkInterface *sender) {
  pending--;
  check();
}

// Phase 2: report once all replies AND all children reports are in
void IdAssignCode::onReport(std::shared_ptr<Message> _msg, P2PNetworkInterface *sender) {
  MessageOf<int> *msg = static_cast<MessageOf<int>*>(_msg.get());
  int s = *msg->getData();
  for (auto &c : children) if (c.itf == sender) c.size = s;
  subtreeSize += s;
  reportsMissing--;
  check();
}

void IdAssignCode::check() {
  if (sizeSent || pending > 0 || reportsMissing > 0) return;
  sizeSent = true;
  if (parent) {
    sendOne(MSG_REPORT, parent, subtreeSize);     // type 4
  } else {                                        // leader: total size known
    g_N = subtreeSize;
    g_bits = (g_N <= 1) ? 1 : (int)ceil(log2((double)g_N));
    g_t12 = RealClock::now();
    distribute(0);                                // leader takes ID 0
  }
}

// Phase 3: child i gets  id + 1 + sum(sizes of previous children)
void IdAssignCode::onAssign(std::shared_ptr<Message> _msg, P2PNetworkInterface *sender) {
  MessageOf<int> *msg = static_cast<MessageOf<int>*>(_msg.get());
  distribute(*msg->getData());
}

void IdAssignCode::distribute(int id) {
  uniqueId = id;
  int next = id + 1;
  for (auto &c : children) { sendOne(MSG_ASSIGN, c.itf, next); next += c.size; }   // type 5
  record();
}

// Verification
void IdAssignCode::record() {
  if (!g_ids.insert(uniqueId).second) g_duplicate = true;
  if (uniqueId > g_maxId) g_maxId = uniqueId;
  if (!isLeader) setColor(Color((uniqueId * 37) % 256, (uniqueId * 91) % 256, (uniqueId * 53) % 256));
  if ((int)g_ids.size() == g_N) {
    g_lastId = getId();
    g_lastPos = module->position.to_string();
    setColor(Color(0, 0, 0));                     // last module reached is BLACK
    printReport();
  }
}

void IdAssignCode::printReport() {
  g_t12Ms   = std::chrono::duration<double, std::milli>(g_t12 - g_start).count();
  g_totalMs = std::chrono::duration<double, std::milli>(RealClock::now() - g_start).count();
  long total = 0; for (int t = 0; t < 5; t++) total += g_msgs[t + 1];
  bool ok = !g_duplicate && g_maxId == g_N - 1 && *g_ids.begin() == 0;
  cout << "\n<<<>>> ID ASSIGNMENT REPORT <<<>>>\n"
       << "Leader                      : module " << g_leaderId << " at " << g_leaderPos << "\n"
       << "Last module reached         : module " << g_lastId << " at " << g_lastPos << "\n"
       << "Modules found by leader (N) : " << g_N << "\n"
       << "ID length                   : " << g_bits << " bits\n"
       << "Unique IDs assigned         : " << g_ids.size() << "  (duplicates: " << (g_duplicate ? "YES" : "none") << ")\n"
       << "IDs cover 0.." << g_N - 1 << "            : " << (ok ? "OK" : "FAILED") << "\n"
       << "REAL time phases 1+2 (ms)   : " << g_t12Ms << "\n"
       << "REAL time total (ms)        : " << g_totalMs << "\n"
       << "Messages type1=" << g_msgs[1] << " type2=" << g_msgs[2] << " type3=" << g_msgs[3]
       << " type4=" << g_msgs[4] << " type5=" << g_msgs[5] << "\n"
       << "Messages TOTAL              : " << total << "\n"
       << "<<<<<<<<<<<<<<<<<<>>>>>>>>>>>>>>>>>>\n" << endl;
}

} // namespace IdAssign