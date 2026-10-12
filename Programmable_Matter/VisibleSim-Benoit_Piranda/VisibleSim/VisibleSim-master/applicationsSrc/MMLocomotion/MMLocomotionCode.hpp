#ifndef MMLocomotionCode_H_
#define MMLocomotionCode_H_

/**
 * Meta-module locomotion for 3D Catoms.
 * The meta-modules shift as a snake: each complete meta-module moves into the
 * neighbouring slot, the direction alternates, and the motion repeats forever.
 **/

#include <array>
#include <vector>
#include <set>
#include <map>
#include <queue>
#include <algorithm>
#include <random>

#include "robots/catoms3D/catoms3DSimulator.h"
#include "robots/catoms3D/catoms3DWorld.h"
#include "robots/catoms3D/catoms3DBlockCode.h"
#include "robots/catoms3D/catoms3DRotationEvents.h"
#include "robots/catoms3D/catoms3DMotionRules.h"

using namespace Catoms3D;
using namespace std;

enum class MMShape { FB, BF };

static const int MOVE_CMD_MSG_ID  = 2001;
static const int MOVE_DONE_MSG_ID = 2002;
static const int MOVE_FAIL_MSG_ID = 2003;

// distance (in lattice cells) between the roots of two neighbouring meta-modules (see config.xml)
static const int MM_SPACING_X = 4;

// Relative positions, in the order that allows dismantling / assembling without blockage
static const std::array<Cell3DPosition, 12> FB_RELATIVE_POSITIONS = {
    Cell3DPosition(-2, -1, 3), Cell3DPosition(-1, -1, 2), Cell3DPosition(-2, -1, 1),
    Cell3DPosition(-1, -1, 3), Cell3DPosition(-1, -1, 1), Cell3DPosition(0, 0, 4),
    Cell3DPosition(0, 0, 0), Cell3DPosition(1, 0, 4), Cell3DPosition(1, 0, 0),
    Cell3DPosition(1, 0, 3), Cell3DPosition(1, 0, 1), Cell3DPosition(2, 1, 2)};
static const std::array<Cell3DPosition, 12> BF_RELATIVE_POSITIONS = {
    Cell3DPosition(-2, 0, 3), Cell3DPosition(-1, 1, 2), Cell3DPosition(-2, 0, 1),
    Cell3DPosition(-1, 0, 3), Cell3DPosition(-1, 0, 1), Cell3DPosition(0, 0, 4),
    Cell3DPosition(0, 0, 0), Cell3DPosition(1, 0, 4), Cell3DPosition(1, 0, 0),
    Cell3DPosition(1, -1, 3), Cell3DPosition(1, -1, 1), Cell3DPosition(2, -1, 2)};

// "module standing on 'from' must go to 'to'"; seq is the index of the module in the shape order
struct MoveCmd  { int seq; Cell3DPosition from; Cell3DPosition to; };
struct MoveDone { int seq; };
struct MoveFail { int seq; };

class MMLocomotionCode : public Catoms3DBlockCode {
private:
    Catoms3DBlock *module = nullptr;

    // ---- coordinator state (only used by the root of the left-most meta-module) ----
    bool isCoordinator = false;
    size_t curIdx = 0;                    // command currently being executed
    bool movingRight = true;              // alternates after each complete chain shift
    int nextSeq = 0;
    std::vector<Cell3DPosition> chainRoots;
    std::vector<MMShape> chainShapes;
    std::vector<MMShape> slotShapes;
    std::vector<MoveCmd> cycleCommands;
    size_t nextDispatchIdx = 0;
    std::set<int> inFlight;
    std::set<int> completed;

    // ---- flooding de-duplication ----
    std::set<int> seenCmd, seenDone;
    std::set<int> seenFail;
    std::map<int, MoveCmd> pendingMoves;

    // ---- mover state ----
    bool moving = false;
    bool moveScheduled = false;
    int moveSeq = -1;
    Cell3DPosition goal;
    std::vector<Cell3DPosition> path;
    size_t stepIdx = 0;
    std::mt19937 randomGenerator{std::random_device{}()};

public:
    MMLocomotionCode(Catoms3DBlock *host);
    ~MMLocomotionCode() {};

    void startup() override;
    void onMotionEnd() override;
    void onInterruptionEvent(std::shared_ptr<Event> event) override;

    // message handlers
    void onMoveCmd(std::shared_ptr<Message> msg, P2PNetworkInterface *sender);
    void onMoveDone(std::shared_ptr<Message> msg, P2PNetworkInterface *sender);
    void onMoveFail(std::shared_ptr<Message> msg, P2PNetworkInterface *sender);

    // shape helpers
    static const std::array<Cell3DPosition, 12>& cellsOf(MMShape s);
    static MMShape opposite(MMShape s);
    bool isComplete(const Cell3DPosition &root, MMShape s);
    bool detectRoot(const Cell3DPosition &p, MMShape &s);

    // coordinator
    void buildCycleCommands();
    void sendNextCommand();
    void floodCmd(const MoveCmd &c, P2PNetworkInterface *except);
    void floodDone(const MoveDone &d, P2PNetworkInterface *except);
    void floodFail(const MoveFail &f, P2PNetworkInterface *except);
    void dispatchAvailableMoves();

    // mover
    void startMove(const MoveCmd &c);
    void startMove(const MoveCmd &c, bool applyDelay);
    void scheduleStep();
    void finishMove();

    // path finding (from the exercise sheet)
    vector<Cell3DPosition> findPath(Cell3DPosition &start, Cell3DPosition &goal);
    bool getAllPossibleMotionsFromPosition(Cell3DPosition position,
                                           vector<Cell3DPosition> &reachablePositions);

    static BlockCode *buildNewBlockCode(BuildingBlock *host) {
        return (new MMLocomotionCode((Catoms3DBlock *)host));
    }
};

#endif /* MMLocomotionCode_H_ */