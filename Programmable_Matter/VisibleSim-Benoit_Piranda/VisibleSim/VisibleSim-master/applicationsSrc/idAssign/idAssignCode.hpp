/**
 * @file idAssignCode.hpp
 * 3-phase unique ID assignment (exploration / size reporting / ID distribution)
 **/
#ifndef idAssignCode_H_
#define idAssignCode_H_

#include "robots/blinkyBlocks/blinkyBlocksSimulator.h"
#include "robots/blinkyBlocks/blinkyBlocksWorld.h"
#include "robots/blinkyBlocks/blinkyBlocksBlockCode.h"
#include <utility>
#include <string>
#include <vector>

using namespace BlinkyBlocks;

namespace IdAssign {

static const int MSG_EXPLORE = 1001;   // type 1
static const int MSG_CONFIRM = 1002;   // type 2
static const int MSG_DECLINE = 1003;   // type 3
static const int MSG_REPORT  = 1004;   // type 4 (int = sub-tree size)
static const int MSG_ASSIGN  = 1005;   // type 5 (int = ID)

struct Child { P2PNetworkInterface *itf; int size; };

class IdAssignCode : public BlinkyBlocksBlockCode {
private:
  BlinkyBlocksBlock *module = nullptr;
  bool isLeader = false;
  int candidateIdx = -1;      // >= 0 if this block takes part in the random leader draw

  bool discovered = false, sizeSent = false;
  P2PNetworkInterface *parent = nullptr;
  std::vector<Child> children;
  int pending = 0;          // type-1 messages still waiting for a type 2 / 3 reply
  int reportsMissing = 0;   // children that have not sent their type 4 yet
  int subtreeSize = 1;      // me + all my descendants
  int uniqueId = -1;

  void explore(P2PNetworkInterface *except);
  void check();
  void distribute(int id);
  void record();
  void printReport();
  void sendOne(int type, P2PNetworkInterface *itf, int value);

public:
  IdAssignCode(BlinkyBlocksBlock *host);
  ~IdAssignCode() {}

  void startup() override;
  void parseUserBlockElements(TiXmlElement *config) override;
  std::string onInterfaceDraw() override;     // text shown in the VisibleSim info window

  void onExplore(std::shared_ptr<Message> _msg, P2PNetworkInterface *sender);
  void onConfirm(std::shared_ptr<Message> _msg, P2PNetworkInterface *sender);
  void onDecline(std::shared_ptr<Message> _msg, P2PNetworkInterface *sender);
  void onReport(std::shared_ptr<Message> _msg, P2PNetworkInterface *sender);
  void onAssign(std::shared_ptr<Message> _msg, P2PNetworkInterface *sender);

  static BlockCode *buildNewBlockCode(BuildingBlock *host) {
    return (new IdAssignCode((BlinkyBlocksBlock*)host));
  }
};

} // namespace IdAssign
#endif
