#ifndef SlidingCubeCode_H_
#define SlidingCubeCode_H_

#include "robots/slidingCubes/slidingCubesSimulator.h"
#include "robots/slidingCubes/slidingCubesWorld.h"
#include "robots/slidingCubes/slidingCubesBlockCode.h"
#include "comm/network.h"

#include <vector>
#include <memory>
#include <set>
#include <string>
#include <limits>
#include <array>

using namespace SlidingCubes;

struct MoveToken
{
    int sequence = 0;
    Cell3DPosition from = Cell3DPosition(0, 0, 0);
    Cell3DPosition to = Cell3DPosition(0, 0, 0);
};

struct ResultToken
{
    int sequence = 0;
    Cell3DPosition from = Cell3DPosition(0, 0, 0);
    Cell3DPosition to = Cell3DPosition(0, 0, 0);
    bool success = false;
};

struct PlannedMove
{
    Cell3DPosition from = Cell3DPosition(0, 0, 0);
    Cell3DPosition to = Cell3DPosition(0, 0, 0);
};

class SlidingCubeCode : public SlidingCubesBlockCode
{
private:
    static constexpr int MOVE_MESSAGE = 1001;
    static constexpr int RESULT_MESSAGE = 1002;

    // Safety limit on the number of individual cube motions.
    static constexpr int MAX_MOTIONS = 500;

    // Maximum number of times the leader may re-plan from the live
    // configuration after a rejected motion.
    static constexpr int MAX_REPLANS = 40;

    SlidingCubesBlock *module = nullptr;
    bool isLeader = false;

    bool moving = false;
    int movingRound = -1;
    Cell3DPosition movingFrom = Cell3DPosition(0, 0, 0);

    int currentRound = 0;
    int totalMotions = 0;
    int lastReceivedRound = -1;

    MoveToken currentMove;

    std::set<std::string> forbiddenMoves;
    std::set<long long> processedResults;
    std::set<std::string> receivedMoveTokens;

    // Leader-only state.
    int activeSequence = -1;
    int replanCount = 0;

    std::vector<PlannedMove> plannedPath;
    std::size_t plannedPathIndex = 0;
    bool planActive = false;

    bool floorOnlyTarget = false;
    bool targetsAreFloorOnly() const;

    std::vector<Cell3DPosition> getTargetCells() const;
    std::vector<SlidingCubesBlock*> getAllCubes() const;

    int distanceBetween(const Cell3DPosition &a, const Cell3DPosition &b) const;
    int configurationDistance(const std::vector<Cell3DPosition> &positions) const;

    std::string makeMoveKey(const Cell3DPosition &from, const Cell3DPosition &to) const;
    std::string makeMoveTokenKey(const MoveToken &token) const;

    bool buildPlan();

    std::vector<std::pair<std::vector<std::array<int, 3>>, PlannedMove>>
    generateAbstractMoves(const std::vector<std::array<int, 3>> &state) const;

    void startNextRound();
    void handleResultAtLeader(const ResultToken &result);
    void executeCalculatedMove();
    void sendResult(bool success);

    void receiveMove(std::shared_ptr<Message> message, P2PNetworkInterface *sender);
    void receiveResult(std::shared_ptr<Message> message, P2PNetworkInterface *sender);

    long long makeResultKey(int sequence, const Cell3DPosition &position) const;
    bool targetComplete() const;

public:
    SlidingCubeCode(SlidingCubesBlock *host);
    ~SlidingCubeCode() override = default;

    void startup() override;
    void parseUserElements(TiXmlDocument *config) override;
    void parseUserBlockElements(TiXmlElement *config) override;
    void onMotionEnd() override;
    void onGlDraw() override;

    static BlockCode *buildNewBlockCode(BuildingBlock *host)
    {
        return new SlidingCubeCode(static_cast<SlidingCubesBlock *>(host));
    }
};

#endif