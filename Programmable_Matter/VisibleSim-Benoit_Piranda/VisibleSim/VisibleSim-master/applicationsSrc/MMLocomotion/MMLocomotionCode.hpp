#ifndef MMLocomotionCode_H_
#define MMLocomotionCode_H_

/**
 * Meta-module locomotion for 3D Catoms.
 * Fully distributed Spatial Pipelining architecture.
 * Nodes hold the Start Token until they have physically cleared the departure zone,
 * creating a safe, concurrent conveyor belt of moving nodes without deadlocks.
 **/

#include <array>
#include <vector>
#include <set>
#include <map>
#include <queue>
#include <algorithm>
#include <random>
#include <cstdint>
#include <iostream>

#include "robots/catoms3D/catoms3DSimulator.h"
#include "robots/catoms3D/catoms3DWorld.h"
#include "robots/catoms3D/catoms3DBlockCode.h"
#include "robots/catoms3D/catoms3DRotationEvents.h"
#include "robots/catoms3D/catoms3DMotionRules.h"

using namespace Catoms3D;
using namespace std;

#define MM_LOG(expr) do { cout << "[" << (getScheduler()->now() / 1000) << " ms] " << expr << endl; } while (0)

enum class MMShape { FB, BF };

static const int MM_SPACING_X = 4;
static const int SAFE_DEPARTURE_STEPS = 6; // Physical gap required before next node can start

static const int TOKEN_MSG_ID = 3001;
static const int RESET_MSG_ID = 3002;

static const int START_JITTER_MAX = 5000;
static const int STEP_JITTER_MAX  = 0;
static const int BACKOFF_MIN      = 2000;
static const int BACKOFF_MAX      = 8000;
static const int MAX_BACKOFFS     = 5;
static const int MAX_REPLANS      = 4;
static const int LOCAL_RETRY_MIN  = 100000;
static const int LOCAL_RETRY_MAX  = 300000;
static const size_t MAX_BFS_NODES = 4000;

// Relative positions engineered for seamless disassembly[cite: 26]
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

struct MoveToken {
    int cycleSeq;
    int curIdx;
    bool movingRight;
    Cell3DPosition srcRef;
    Cell3DPosition newRef;
    MMShape srcShape;
    MMShape newShape;
    Cell3DPosition targetPos;
    Cell3DPosition goalPos;
};

struct CycleReset {
    int cycleSeq;
    bool movingRight;
};

enum TimerKind { TIMER_START_MOVE = 1, TIMER_STEP_RETRY = 2, TIMER_REPLAN = 4 };
struct TimerData { int kind = 0; };

class MMLocomotionCode : public Catoms3DBlockCode {
private:
    Catoms3DBlock *module = nullptr;

    std::set<std::pair<int, int>> seenTokens;
    std::set<int> seenResets;

    bool moving = false;
    bool tokenPassed = false;
    MoveToken myToken;
    Cell3DPosition goal;

    int blockedCount = 0;
    int replanCount = 0;
    bool hasReservation = false;
    Cell3DPosition reservedCell;
    std::vector<Cell3DPosition> path;
    size_t stepIdx = 0;
    std::mt19937 randomGenerator{std::random_device{}()};

public:
    MMLocomotionCode(Catoms3DBlock *host);
    ~MMLocomotionCode() {};

    void startup() override;
    void onMotionEnd() override;
    void onInterruptionEvent(std::shared_ptr<Event> event) override;

    void onMoveToken(std::shared_ptr<Message> msg, P2PNetworkInterface *sender);
    void onCycleReset(std::shared_ptr<Message> msg, P2PNetworkInterface *sender);

    void checkInitiator(int cycleSeq, bool movingRight);
    void executeToken(const MoveToken &t);
    void passTokenToNext();
    void finishMove();
    int getArrIdx(int curIdx, bool movingRight);

    static const std::array<Cell3DPosition, 12>& cellsOf(MMShape s);
    static MMShape opposite(MMShape s);
    bool isComplete(const Cell3DPosition &root, MMShape s);
    bool detectRoot(const Cell3DPosition &p, MMShape &s);
    bool hasSupport(const Cell3DPosition &p);

    void replan();
    void retryLater(const char *reason);
    void scheduleStep();
    void backoffOrReplan();
    void reserveCell(const Cell3DPosition &c);
    void releaseReservation();
    int  randomBetween(int lo, int hi);

    vector<Cell3DPosition> findPath(Cell3DPosition &start, Cell3DPosition &goal);
    bool getAllPossibleMotionsFromPosition(Cell3DPosition position, vector<Cell3DPosition> &reachablePositions);

    static BlockCode *buildNewBlockCode(BuildingBlock *host) {
        return (new MMLocomotionCode((Catoms3DBlock *)host));
    }
};

#endif /* MMLocomotionCode_H_ */