/**
 * @file leaderElectionCode.hpp
 * Leader election for modular robots (weights, recruitment, tree value, competition,
 * dismantling, potential leader).  One source for two robots:
 *   compile with -CATOMS3D  -> Catoms3D   (chain)
 *   compile without              -> BlinkyBlocks (grid, random)
 **/
#ifndef leaderElectionCode_H_
#define leaderElectionCode_H_

#ifdef USE_CATOMS3D
  #include "robots/catoms3D/catoms3DSimulator.h"
  #include "robots/catoms3D/catoms3DWorld.h"
  #include "robots/catoms3D/catoms3DBlockCode.h"
  using namespace Catoms3D;
  typedef Catoms3DBlock     RobotBlock;
  typedef Catoms3DBlockCode RobotBlockCode;
#else
  #include "robots/blinkyBlocks/blinkyBlocksSimulator.h"
  #include "robots/blinkyBlocks/blinkyBlocksWorld.h"
  #include "robots/blinkyBlocks/blinkyBlocksBlockCode.h"
  using namespace BlinkyBlocks;
  typedef BlinkyBlocksBlock     RobotBlock;
  typedef BlinkyBlocksBlockCode RobotBlockCode;
#endif
#include <vector>
#include <set>
#include <string>
#include <memory>

namespace LeaderElection {

enum MsgType {
  M_RECRUIT = 1001,   // tree root / parent -> neighbour : "join my tree"
  M_ACCEPT,           // new child -> parent
  M_REFUSE,           // "already recruited"
  M_SUM,              // child -> parent : sum of weights and number of modules
  M_VALUE,            // root -> children : tree value, "ready to compete"
  M_CHOICE,           // ready module -> neighbour of another tree : my tree value
  M_DISMANTLE_UP,     // loser module -> parent, up to the root
  M_DISMANTLE,        // loser root -> children : "free yourselves"
  M_FREED,            // freed module -> neighbours : "I can be recruited"
  M_REGROW,           // tree module -> root : "a free neighbour appeared, grow again"
  M_RESTART,          // root -> children : start a new counting round
  M_PL,               // potential leader, children -> parent
  M_LEADER            // leader found, root -> children
};
static const int NB_TYPES = 13;

struct Pay { int tok, a, b, c; };                 // payload of every message
struct Value {                                    // value of a tree: compared as (sum, count, token)
  int sum = 0, cnt = 0, tok = 0;
  bool operator<(const Value &o) const {
    if (sum != o.sum) return sum < o.sum;
    if (cnt != o.cnt) return cnt < o.cnt;
    return tok < o.tok;
  }
  bool operator==(const Value &o) const { return sum == o.sum && cnt == o.cnt && tok == o.tok; }
};
struct Child { P2PNetworkInterface *itf; bool got; bool pl; };

enum State { S_FREE, S_GROW, S_SENT, S_READY };   // not in a tree / counting / reported / has tree value

class LeaderElectionCode : public RobotBlockCode {
private:
  RobotBlock *module = nullptr;
  std::vector<P2PNetworkInterface*> itfs;

  // phase 1: weight
  int weight = 0, degree = 0;
  // trees
  State st = S_FREE;
  P2PNetworkInterface *parent = nullptr;
  std::vector<Child> children;
  int tok = 0, pending = 0, sum = 0, cnt = 0;
  Value val;
  std::set<P2PNetworkInterface*> same;            // non-tree links whose neighbour is in MY tree
  bool plSent = false, regrowQ = false, done = false, leader = false;

  Child *child(P2PNetworkInterface *p);
  bool isTreeLink(P2PNetworkInterface *p);
  void send(P2PNetworkInterface *p, int type, int t = 0, int a = 0, int b = 0, int c = 0);
  void becomeRoot();
  void explore();
  void startRound();
  void check();
  void myReady();
  void maybePL();
  void elected();
  void informed();
  void dismantleTree();
  void freeSelf();
  void printReport();

public:
  LeaderElectionCode(RobotBlock *host);
  ~LeaderElectionCode() {}

  void startup() override;
  void onMsg(std::shared_ptr<Message> m, P2PNetworkInterface *from);
  void onInterruptionEvent(std::shared_ptr<Event> ev) override;
  std::string onInterfaceDraw() override;

  static BlockCode *buildNewBlockCode(BuildingBlock *host) {
    return (new LeaderElectionCode((RobotBlock*)host));
  }
};

} // namespace LeaderElection
#endif
