/**
 * @file leaderElectionCode.cpp
 **/
#include "leaderElectionCode.hpp"
#include <iostream>
#include <chrono>
#include <random>
#include <cstdio>

using namespace std;
namespace LeaderElection {

// Time a module with i connections waits before it may start its own tree
// It must be longer than the time a recruitment wave needs to cross the robot (about 1 ms per hop).
static const Time T_UNIT = 5000000;

// stats for simulator
typedef chrono::steady_clock RealClock;
static long g_msgs[NB_TYPES] = {0};
static int g_N = 0, g_informed = 0, g_rounds = 0, g_dismantles = 0, g_leaderId = -1;
static string g_leaderPos;
static RealClock::time_point g_start, g_tLeader, g_tEnd;
static Time g_simLeader = 0, g_simEnd = 0;
static bool g_reported = false;
static mt19937 g_rng(random_device{}());
static int newTok() { return (int)(g_rng() % 1000000000u) + 1; }

LeaderElectionCode::LeaderElectionCode(RobotBlock *host) : RobotBlockCode(host), module(host) {
  if (not host) return;
  for (int t = M_RECRUIT; t < M_RECRUIT + NB_TYPES; t++)
    addMessageEventFunc2(t, std::bind(&LeaderElectionCode::onMsg, this, std::placeholders::_1, std::placeholders::_2));
}

// helpers
Child *LeaderElectionCode::child(P2PNetworkInterface *p) {
  for (auto &c : children) if (c.itf == p) return &c;
  return nullptr;
}
bool LeaderElectionCode::isTreeLink(P2PNetworkInterface *p) { return p == parent || child(p) != nullptr; }

void LeaderElectionCode::send(P2PNetworkInterface *p, int type, int t, int a, int b, int c) {
  g_msgs[type - M_RECRUIT]++;
  sendMessage("m", new MessageOf<Pay>(type, Pay{t, a, b, c}), p, 20000, 0);
}

// phase 1: weight, start
void LeaderElectionCode::startup() {
  itfs = module->getP2PNetworkInterfaces();
  for (size_t i = 0; i < itfs.size(); i++)                 // weight = sum of 2^i over connected interfaces
    if (itfs[i]->isConnected()) { weight += 1 << i; degree++; }
  if (g_N++ == 0) g_start = RealClock::now();
  if (degree == 0) { elected(); return; }
  if (degree == 1) becomeRoot();                           // 1 connection: prospective leader at once
  else getScheduler()->schedule(new InterruptionEvent<int>(getScheduler()->now() + (degree - 1) * T_UNIT, module, 0));
}

void LeaderElectionCode::onInterruptionEvent(shared_ptr<Event>) {
  if (st == S_FREE && !done) becomeRoot();                 // waited and was not recruited
}

// phase 2: recruitment
void LeaderElectionCode::becomeRoot() {
  st = S_GROW; parent = nullptr; children.clear();
  tok = newTok(); sum = weight; cnt = 1; pending = 0;
  setColor(Color((tok * 37) % 256, (tok * 91) % 256, (tok * 53) % 256));
  explore(); check();
}

void LeaderElectionCode::explore() {                       // RECRUIT every link that is not a tree link
  for (auto *p : itfs)
    if (p->isConnected() && !isTreeLink(p)) { send(p, M_RECRUIT, tok); pending++; }
}

void LeaderElectionCode::startRound() {                    // root: new counting round (the tree grew)
  g_rounds++;
  tok = newTok(); st = S_GROW; sum = weight; cnt = 1; pending = 0; regrowQ = false;
  for (auto &c : children) { c.got = false; c.pl = false; send(c.itf, M_RESTART, tok); }
  explore(); check();
}

// phase 3: tree value
void LeaderElectionCode::check() {
  if (st != S_GROW || pending > 0) return;
  for (auto &c : children) if (!c.got) return;
  if (parent) { st = S_SENT; send(parent, M_SUM, tok, sum, cnt); return; }
  if (regrowQ) { startRound(); return; }
  st = S_READY; val = Value{sum, cnt, tok};                // root knows the tree value
  for (auto &c : children) send(c.itf, M_VALUE, tok, sum, cnt);
  myReady();
}

// phase 4: competition
void LeaderElectionCode::myReady() {
  same.clear(); plSent = false;
  for (auto &c : children) c.pl = false;
  for (auto *p : itfs)
    if (p->isConnected() && !isTreeLink(p)) send(p, M_CHOICE, val.tok, val.sum, val.cnt, 0);
  maybePL();
}

// phase 5: dismantling
void LeaderElectionCode::dismantleTree() {
  if (parent) send(parent, M_DISMANTLE_UP, tok);
  else { g_dismantles++; freeSelf(); }
}

void LeaderElectionCode::freeSelf() {
  vector<P2PNetworkInterface*> kids;
  for (auto &c : children) { kids.push_back(c.itf); send(c.itf, M_DISMANTLE, tok); }
  st = S_FREE; parent = nullptr; children.clear(); tok = 0; pending = 0; regrowQ = false;
  setColor(Color(120, 120, 120));
  for (auto *p : itfs) {
    bool isKid = false;
    for (auto *k : kids) if (k == p) isKid = true;
    if (p->isConnected() && !isKid) send(p, M_FREED);
  }
}

// phase 6: leader
void LeaderElectionCode::maybePL() {
  if (st != S_READY || plSent || done) return;
  for (auto *p : itfs) if (p->isConnected() && !isTreeLink(p) && !same.count(p)) return;
  for (auto &c : children) if (!c.pl) return;
  plSent = true;
  if (parent) send(parent, M_PL, tok); else elected();     // the root of the last tree is the leader
}

void LeaderElectionCode::elected() {
  leader = true; done = true;
  g_leaderId = getId(); g_leaderPos = module->position.to_string();
  g_tLeader = RealClock::now(); g_simLeader = getScheduler()->now();
  cout << "LEADER ELECTED: module " << g_leaderId << " at " << g_leaderPos << endl;
  setColor(RED);
  for (auto &c : children) send(c.itf, M_LEADER);
  informed();
}

void LeaderElectionCode::informed() {
  if (++g_informed == g_N && !g_reported) {
    g_tEnd = RealClock::now(); g_simEnd = getScheduler()->now(); g_reported = true;
    printReport();
  }
}

// message handling
void LeaderElectionCode::onMsg(shared_ptr<Message> m, P2PNetworkInterface *from) {
  Pay p = *static_cast<MessageOf<Pay>*>(m.get())->getData();
  if (done && m->type != M_LEADER) return;
  switch (m->type) {
  case M_RECRUIT:
    if (st == S_FREE) {
      st = S_GROW; parent = from; tok = p.tok; children.clear(); sum = weight; cnt = 1; pending = 0;
      setColor(Color((tok * 37) % 256, (tok * 91) % 256, (tok * 53) % 256));
      send(from, M_ACCEPT, p.tok); explore(); check();
    } else send(from, M_REFUSE, p.tok);
    break;
  case M_ACCEPT:
    if (st == S_GROW && p.tok == tok) { pending--; children.push_back({from, false, false}); check(); }
    else send(from, M_DISMANTLE, p.tok);                   // stale: the new child must free itself
    break;
  case M_REFUSE:
    if (st == S_GROW && p.tok == tok) { pending--; check(); }
    break;
  case M_SUM: {
    Child *c = child(from);
    if (st == S_GROW && p.tok == tok && c) { c->got = true; sum += p.a; cnt += p.b; check(); }
    break; }
  case M_VALUE:
    if (st == S_SENT && p.tok == tok && from == parent) {
      st = S_READY; val = Value{p.a, p.b, p.tok};
      for (auto &c : children) send(c.itf, M_VALUE, tok, p.a, p.b);
      myReady();
    }
    break;
  case M_CHOICE: {
    if (st != S_READY) break;
    Value v{p.a, p.b, p.tok};
    if (val < v) dismantleTree();                          // the other tree is stronger: I lose
    else {
      if (v == val) same.insert(from);                     // same tree
      if (p.c == 0) send(from, M_CHOICE, val.tok, val.sum, val.cnt, 1);   // answer once
      maybePL();
    }
    break; }
  case M_DISMANTLE_UP:
    if (st == S_READY && p.tok == tok) dismantleTree();
    break;
  case M_DISMANTLE:
    if (st != S_FREE && p.tok == tok && from == parent) freeSelf();
    break;
  case M_FREED:
    if (st != S_FREE) {
      if (parent) send(parent, M_REGROW, tok);
      else if (st == S_READY) startRound(); else regrowQ = true;
    }
    break;
  case M_REGROW:
    if (st != S_FREE && p.tok == tok && child(from)) {
      if (parent) send(parent, M_REGROW, tok);
      else if (st == S_READY) startRound(); else regrowQ = true;
    }
    break;
  case M_RESTART:
    if (st != S_FREE && from == parent) {
      tok = p.tok; st = S_GROW; sum = weight; cnt = 1; pending = 0;
      setColor(Color((tok * 37) % 256, (tok * 91) % 256, (tok * 53) % 256));
      for (auto &c : children) { c.got = false; c.pl = false; send(c.itf, M_RESTART, tok); }
      explore(); check();
    }
    break;
  case M_PL: {
    Child *c = child(from);
    if (st == S_READY && p.tok == tok && c) { c->pl = true; maybePL(); }
    break; }
  case M_LEADER:
    if (!done) {
      done = true; setColor(GREEN);
      for (auto &c : children) send(c.itf, M_LEADER);
      informed();
    }
    break;
  }
}

// output
string LeaderElectionCode::onInterfaceDraw() {
  static const char *names[] = {"free", "counting", "reported", "ready"};
  string s = "Module " + to_string(getId()) + (leader ? " (LEADER)" : "") + "\nweight " + to_string(weight) +
             ", connections " + to_string(degree) + "\nstate: " + names[st];
  if (st == S_READY) s += "\ntree value: sum " + to_string(val.sum) + ", modules " + to_string(val.cnt);
  if (g_leaderId >= 0) s += "\nleader: module " + to_string(g_leaderId) + " at " + g_leaderPos;
  if (g_reported) {
    long total = 0; for (int i = 0; i < NB_TYPES; i++) total += g_msgs[i];
    char buf[200];
    snprintf(buf, sizeof(buf), "\n<<< SYSTEM >>>\nREAL time: %.3f ms\nSIM time: %.1f ms\nmessages: %ld  dismantles: %d",
             chrono::duration<double, milli>(g_tEnd - g_start).count(), g_simEnd / 1000.0, total, g_dismantles);
    s += buf;
  }
  return s;
}

void LeaderElectionCode::printReport() {
  static const char *names[] = {"RECRUIT","ACCEPT","REFUSE","SUM","VALUE","CHOICE","DISMANTLE_UP","DISMANTLE","FREED","REGROW","RESTART","PL","LEADER"};
  long total = 0; for (int i = 0; i < NB_TYPES; i++) total += g_msgs[i];
  cout << "\n<<<>>> LEADER ELECTION REPORT <<<>>>\n"
       << "Modules                    : " << g_N << "\n"
       << "Leader                     : module " << g_leaderId << " at " << g_leaderPos << "\n"
       << "Dismantled trees           : " << g_dismantles << "   (counting rounds after growth: " << g_rounds << ")\n"
       << "REAL time to elect (ms)    : " << chrono::duration<double, milli>(g_tLeader - g_start).count() << "\n"
       << "REAL time, all informed(ms): " << chrono::duration<double, milli>(g_tEnd - g_start).count() << "\n"
       << "SIM  time to elect (ms)    : " << g_simLeader / 1000.0 << "\n"
       << "SIM  time, all informed(ms): " << g_simEnd / 1000.0 << "\n";
  for (int i = 0; i < NB_TYPES; i++) cout << "  " << names[i] << " = " << g_msgs[i] << (i % 4 == 3 ? "\n" : "   ");
  cout << "\nMessages TOTAL             : " << total << "\n<<<<<<<<<<<<<<<<<<>>>>>>>>>>>>>>>>>>\n" << endl;
}

} // namespace LeaderElection
