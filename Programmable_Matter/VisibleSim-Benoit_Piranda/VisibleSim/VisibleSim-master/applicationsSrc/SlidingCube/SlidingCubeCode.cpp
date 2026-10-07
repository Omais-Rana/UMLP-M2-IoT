#include "SlidingCubeCode.hpp"

#include <cmath>
#include <iostream>
#include <algorithm>
#include <limits>
#include <sstream>
#include <set>
#include <map>
#include <array>
#include <queue>
#include <unordered_map>
#include <functional>

#ifndef M_PI
#define M_PI 3.14159265358979323846
#endif

namespace
{
    using GridPoint = std::array<int, 3>;

    // Set while parsing the XML: true if at least one block has
    // leader="true". Configs 01/02/03 have none.
    bool xmlHasExplicitLeader = false;

    struct AbstractRule
    {
        GridPoint destinationDelta{};
        GridPoint pivot{};
        std::vector<GridPoint> cells;
        bool translation = false;
    };

    GridPoint gpAdd(const GridPoint &a, const GridPoint &b)
    {
        return {a[0] + b[0], a[1] + b[1], a[2] + b[2]};
    }

    GridPoint gpNeg(const GridPoint &a)
    {
        return {-a[0], -a[1], -a[2]};
    }

    GridPoint gpMul(int k, const GridPoint &a)
    {
        return {k * a[0], k * a[1], k * a[2]};
    }

    GridPoint gpCross(const GridPoint &a, const GridPoint &b)
    {
        return {
            a[1] * b[2] - a[2] * b[1],
            a[2] * b[0] - a[0] * b[2],
            a[0] * b[1] - a[1] * b[0]
        };
    }

    bool gpLess(const GridPoint &a, const GridPoint &b)
    {
        if (a[2] != b[2]) return a[2] < b[2];
        if (a[1] != b[1]) return a[1] < b[1];
        return a[0] < b[0];
    }

    // Compact key (coordinates are small, so one char each is enough).
    std::string abstractKey(const std::vector<GridPoint> &state)
    {
        std::string key;
        key.reserve(state.size() * 3);
        for (const auto &p : state)
        {
            key.push_back(static_cast<char>(p[0] + 1));
            key.push_back(static_cast<char>(p[1] + 1));
            key.push_back(static_cast<char>(p[2] + 1));
        }
        return key;
    }

    int chebyshev(const GridPoint &a, const GridPoint &b)
    {
        return std::max(
            std::abs(a[0] - b[0]),
            std::max(std::abs(a[1] - b[1]), std::abs(a[2] - b[2]))
        );
    }

    // Optimal one-to-one assignment cost (Hungarian, O(n^3)).
    int assignmentCost(const std::vector<GridPoint> &pos,
                       const std::vector<GridPoint> &tgt)
    {
        const int n = static_cast<int>(pos.size());
        const int INF = 1000000000;
        std::vector<int> u(n + 1), v(n + 1), p(n + 1), way(n + 1);

        for (int i = 1; i <= n; ++i)
        {
            p[0] = i;
            int j0 = 0;
            std::vector<int> minv(n + 1, INF);
            std::vector<char> used(n + 1, false);

            do
            {
                used[j0] = true;
                const int i0 = p[j0];
                int delta = INF;
                int j1 = 0;

                for (int j = 1; j <= n; ++j)
                {
                    if (used[j]) continue;
                    const int cur =
                        chebyshev(pos[i0 - 1], tgt[j - 1]) - u[i0] - v[j];
                    if (cur < minv[j]) { minv[j] = cur; way[j] = j0; }
                    if (minv[j] < delta) { delta = minv[j]; j1 = j; }
                }

                for (int j = 0; j <= n; ++j)
                {
                    if (used[j]) { u[p[j]] += delta; v[j] -= delta; }
                    else minv[j] -= delta;
                }
                j0 = j1;
            }
            while (p[j0] != 0);

            do
            {
                const int j1 = way[j0];
                p[j0] = p[j1];
                j0 = j1;
            }
            while (j0 != 0);
        }
        return -v[0];
    }

    // Face-connectivity of the whole set of cubes.
    bool isConnected(const std::vector<GridPoint> &state)
    {
        if (state.empty()) return true;

        static const GridPoint dirs[6] = {
            {1,0,0}, {0,1,0}, {0,0,1},
            {-1,0,0}, {0,-1,0}, {0,0,-1}
        };

        const std::set<GridPoint> all(state.begin(), state.end());
        std::set<GridPoint> seen;
        std::vector<GridPoint> stack;
        stack.push_back(state[0]);
        seen.insert(state[0]);

        while (!stack.empty())
        {
            const GridPoint c = stack.back();
            stack.pop_back();
            for (const GridPoint &d : dirs)
            {
                const GridPoint n = gpAdd(c, d);
                if (all.count(n) && !seen.count(n))
                {
                    seen.insert(n);
                    stack.push_back(n);
                }
            }
        }
        return seen.size() == state.size();
    }

    std::vector<AbstractRule> buildSlidingCubeRules()
    {
        static const GridPoint dirs[6] = {
            {1,0,0}, {0,1,0}, {0,0,1},
            {-1,0,0}, {0,-1,0}, {0,0,-1}
        };

        std::vector<AbstractRule> rules;
        rules.reserve(48);

        for (int pivot = 0; pivot < 6; ++pivot)
        {
            for (int direction = 0; direction < 6; ++direction)
            {
                if (direction == pivot || direction == (pivot + 3) % 6)
                    continue;

                AbstractRule translation;
                translation.translation = true;
                translation.pivot = dirs[pivot];
                translation.destinationDelta = dirs[direction];
                translation.cells.push_back(dirs[pivot]);
                translation.cells.push_back(
                    gpAdd(dirs[pivot], dirs[direction])
                );
                rules.push_back(translation);

                const GridPoint dir = gpCross(dirs[pivot], dirs[direction]);

                AbstractRule rotation;
                rotation.translation = false;
                rotation.pivot = dirs[pivot];
                rotation.destinationDelta = gpAdd(dirs[pivot], dir);
                rotation.cells.push_back(dir);
                rotation.cells.push_back(gpMul(2, dir));
                rotation.cells.push_back(
                    gpAdd(dirs[pivot], gpMul(2, dir))
                );
                rotation.cells.push_back(gpNeg(dirs[pivot]));
                rotation.cells.push_back(
                    gpAdd(dir, gpNeg(dirs[pivot]))
                );
                rules.push_back(rotation);
            }
        }
        return rules;
    }
}


// ============================================================
// CONSTRUCTOR
// ============================================================

SlidingCubeCode::SlidingCubeCode(SlidingCubesBlock *host)
    : SlidingCubesBlockCode(host),
      module(host)
{
    if (!host)
        return;

    addMessageEventFunc2(
        MOVE_MESSAGE,
        [this](std::shared_ptr<Message> message,
               P2PNetworkInterface *sender)
        {
            receiveMove(message, sender);
        }
    );

    addMessageEventFunc2(
        RESULT_MESSAGE,
        [this](std::shared_ptr<Message> message,
               P2PNetworkInterface *sender)
        {
            receiveResult(message, sender);
        }
    );
}


// ============================================================
// XML
// ============================================================

void SlidingCubeCode::parseUserElements(TiXmlDocument *config)
{
}

void SlidingCubeCode::parseUserBlockElements(TiXmlElement *config)
{
    const char *attr = config->Attribute("leader");

    isLeader = attr && Simulator::extractBoolFromString(attr);

    if (isLeader)
        xmlHasExplicitLeader = true;
}


// ============================================================
// STARTUP
// ============================================================

void SlidingCubeCode::startup()
{
    setColor(GREY);

    if (target && target->isInTarget(module->position))
        setColor(GREEN);

    if (!xmlHasExplicitLeader)
    {
        const std::vector<SlidingCubesBlock*> cubes = getAllCubes();
        isLeader = !cubes.empty() && cubes.front() == module;
    }

    if (!isLeader)
        return;

    std::cout << "Robot " << getId() << " is the LEADER." << std::endl;

    receivedMoveTokens.clear();
    forbiddenMoves.clear();
    processedResults.clear();
    plannedPath.clear();
    plannedPathIndex = 0;
    planActive = false;

    activeSequence = -1;
    replanCount = 0;
    currentRound = 0;
    totalMotions = 0;
    lastReceivedRound = -1;
    moving = false;

    std::cout
        << std::endl
        << "=========================================="
        << std::endl
        << "      SLIDING CUBES SOLVER"
        << std::endl
        << "=========================================="
        << std::endl;

    std::vector<Cell3DPosition> positions;
    for (SlidingCubesBlock *cube : getAllCubes())
        positions.push_back(cube->position);

    std::cout
        << "Cubes = " << positions.size()
        << " | targets = " << getTargetCells().size()
        << " | initial distance = " << configurationDistance(positions)
        << std::endl;

    startNextRound();
}


// ============================================================
// GET TARGET CELLS
// ============================================================

std::vector<Cell3DPosition> SlidingCubeCode::getTargetCells() const
{
    std::vector<Cell3DPosition> targets;

    if (!lattice || !target)
        return targets;

    auto bounds = lattice->getGridUpperBounds();

    for (int z = 0; z <= bounds[2]; ++z)
        for (int y = 0; y <= bounds[1]; ++y)
            for (int x = 0; x <= bounds[0]; ++x)
            {
                Cell3DPosition p(x, y, z);
                if (target->isInTarget(p))
                    targets.push_back(p);
            }

    return targets;
}


bool SlidingCubeCode::targetsAreFloorOnly() const
{
    for (const Cell3DPosition &t : getTargetCells())
        if (t[2] != 0)
            return false;
    return true;
}


// ============================================================
// GET ALL CUBES (sorted by z, y, x)
// ============================================================

std::vector<SlidingCubesBlock*> SlidingCubeCode::getAllCubes() const
{
    std::vector<SlidingCubesBlock*> cubes;

    if (!lattice)
        return cubes;

    auto bounds = lattice->getGridUpperBounds();

    for (int z = 0; z <= bounds[2]; ++z)
        for (int y = 0; y <= bounds[1]; ++y)
            for (int x = 0; x <= bounds[0]; ++x)
            {
                BuildingBlock *block =
                    lattice->getBlock(Cell3DPosition(x, y, z));

                if (block != nullptr)
                    cubes.push_back(static_cast<SlidingCubesBlock*>(block));
            }

    std::sort(
        cubes.begin(), cubes.end(),
        [](SlidingCubesBlock *a, SlidingCubesBlock *b)
        {
            if (a->position[2] != b->position[2])
                return a->position[2] < b->position[2];
            if (a->position[1] != b->position[1])
                return a->position[1] < b->position[1];
            return a->position[0] < b->position[0];
        }
    );

    return cubes;
}


// ============================================================
// DISTANCES
// ============================================================

int SlidingCubeCode::distanceBetween(
    const Cell3DPosition &a,
    const Cell3DPosition &b
) const
{
    const int dx = std::abs(a[0] - b[0]);
    const int dy = std::abs(a[1] - b[1]);
    const int dz = std::abs(a[2] - b[2]);
    return std::max(dx, std::max(dy, dz));
}

int SlidingCubeCode::configurationDistance(
    const std::vector<Cell3DPosition> &positions
) const
{
    const std::vector<Cell3DPosition> targets = getTargetCells();

    if (positions.empty() || positions.size() != targets.size())
        return std::numeric_limits<int>::max() / 4;

    std::vector<GridPoint> p, t;
    for (const auto &c : positions) p.push_back({c[0], c[1], c[2]});
    for (const auto &c : targets)   t.push_back({c[0], c[1], c[2]});

    return assignmentCost(p, t);
}


// ============================================================
// KEYS
// ============================================================

std::string SlidingCubeCode::makeMoveKey(
    const Cell3DPosition &from,
    const Cell3DPosition &to
) const
{
    std::ostringstream key;
    key << from[0] << "," << from[1] << "," << from[2]
        << "->"
        << to[0] << "," << to[1] << "," << to[2];
    return key.str();
}

std::string SlidingCubeCode::makeMoveTokenKey(
    const MoveToken &token
) const
{
    std::ostringstream key;
    key << token.sequence << ":"
        << token.from[0] << "," << token.from[1] << "," << token.from[2]
        << "->"
        << token.to[0] << "," << token.to[1] << "," << token.to[2];
    return key.str();
}


// ============================================================
// ABSTRACT MOVE GENERATION
// ============================================================

std::vector<
    std::pair<std::vector<std::array<int, 3>>, PlannedMove>
>
SlidingCubeCode::generateAbstractMoves(
    const std::vector<std::array<int, 3>> &state
) const
{
    std::vector<std::pair<std::vector<GridPoint>, PlannedMove>> result;

    if (!lattice || state.empty())
        return result;

    static const std::vector<AbstractRule> rules = buildSlidingCubeRules();

    const std::set<GridPoint> occupied(state.begin(), state.end());

    auto isOccupied = [&occupied](const GridPoint &p)
    {
        return occupied.find(p) != occupied.end();
    };

    for (std::size_t index = 0; index < state.size(); ++index)
    {
        const GridPoint &from = state[index];

        for (const AbstractRule &rule : rules)
        {
            const GridPoint destination =
                gpAdd(from, rule.destinationDelta);

            if (!lattice->isInGrid(Cell3DPosition(
                    destination[0], destination[1], destination[2])))
                continue;

            if (isOccupied(destination))
                continue;

            if (floorOnlyTarget && destination[2] > 0)
                continue;

            bool valid = true;

            if (rule.translation)
            {
                for (const GridPoint &required : rule.cells)
                    if (!isOccupied(gpAdd(from, required)))
                    {
                        valid = false;
                        break;
                    }
            }
            else
            {
                if (!isOccupied(gpAdd(from, rule.pivot)))
                {
                    valid = false;
                }
                else
                {
                    for (const GridPoint &freeCell : rule.cells)
                        if (isOccupied(gpAdd(from, freeCell)))
                        {
                            valid = false;
                            break;
                        }
                }
            }

            if (!valid)
                continue;

            PlannedMove move;
            move.from = Cell3DPosition(from[0], from[1], from[2]);
            move.to = Cell3DPosition(
                destination[0], destination[1], destination[2]);

            if (!forbiddenMoves.empty() &&
                forbiddenMoves.count(makeMoveKey(move.from, move.to)))
                continue;

            std::vector<GridPoint> next = state;
            next[index] = destination;
            std::sort(next.begin(), next.end(), gpLess);

            if (!isConnected(next))
                continue;

            result.emplace_back(std::move(next), move);
        }
    }

    return result;
}


// ============================================================
// PLANNER: weighted A* from the LIVE configuration
// ============================================================

bool SlidingCubeCode::buildPlan()
{
    plannedPath.clear();
    plannedPathIndex = 0;
    planActive = false;

    const std::vector<SlidingCubesBlock*> cubes = getAllCubes();
    const std::vector<Cell3DPosition> targets = getTargetCells();

    floorOnlyTarget = targetsAreFloorOnly();

    if (cubes.empty() || cubes.size() != targets.size())
    {
        std::cout << "Planner: number of cubes (" << cubes.size()
                  << ") != number of target cells (" << targets.size()
                  << ")." << std::endl;
        return false;
    }

    std::vector<GridPoint> initial;
    for (SlidingCubesBlock *cube : cubes)
        initial.push_back({cube->position[0],
                           cube->position[1],
                           cube->position[2]});
    std::sort(initial.begin(), initial.end(), gpLess);

    std::vector<GridPoint> goal;
    for (const Cell3DPosition &t : targets)
        goal.push_back({t[0], t[1], t[2]});
    std::sort(goal.begin(), goal.end(), gpLess);

    if (initial == goal)
        return true;

    const std::string initialKey = abstractKey(initial);
    const std::string goalKey = abstractKey(goal);

    std::vector<double> weights;
    if (cubes.size() <= 8)
        weights = {1.0, 4.0, 8.0};
    else
        weights = {4.0, 8.0, 16.0};

    struct Info
    {
        int g = 0;
        std::string parent;
        PlannedMove move;
        std::vector<GridPoint> state;
    };

    struct Node
    {
        double f = 0;
        int g = 0;
        std::string key;
    };

    struct CompareNode
    {
        bool operator()(const Node &a, const Node &b) const
        {
            if (a.f != b.f) return a.f > b.f;
            return a.g < b.g;
        }
    };

    const int MAX_EXPANSIONS = 150000;

    for (const double weight : weights)
    {
        std::priority_queue<Node, std::vector<Node>, CompareNode> open;
        std::unordered_map<std::string, Info> info;
        info.reserve(50000);

        Info start;
        start.g = 0;
        start.state = initial;
        info[initialKey] = start;

        open.push({weight * assignmentCost(initial, goal), 0, initialKey});

        std::string reachedKey;
        int expansions = 0;

        while (!open.empty() && expansions < MAX_EXPANSIONS)
        {
            const Node current = open.top();
            open.pop();

            auto it = info.find(current.key);
            if (it == info.end() || it->second.g != current.g)
                continue;

            if (current.key == goalKey)
            {
                reachedKey = current.key;
                break;
            }

            ++expansions;

            const std::vector<GridPoint> currentState = it->second.state;
            const auto successors = generateAbstractMoves(currentState);

            for (const auto &successor : successors)
            {
                const std::string nextKey = abstractKey(successor.first);
                const int g = current.g + 1;

                auto old = info.find(nextKey);
                if (old != info.end() && g >= old->second.g)
                    continue;

                Info entry;
                entry.g = g;
                entry.parent = current.key;
                entry.move = successor.second;
                entry.state = successor.first;
                info[nextKey] = std::move(entry);

                open.push({
                    g + weight * assignmentCost(successor.first, goal),
                    g,
                    nextKey
                });
            }
        }

        if (reachedKey.empty())
        {
            std::cout << "Planner: weight " << weight
                      << " failed after " << expansions
                      << " expansions." << std::endl;
            continue;
        }

        std::vector<PlannedMove> reversed;
        std::string key = reachedKey;
        while (key != initialKey)
        {
            const Info &e = info[key];
            reversed.push_back(e.move);
            key = e.parent;
        }

        plannedPath.assign(reversed.rbegin(), reversed.rend());
        plannedPathIndex = 0;
        planActive = !plannedPath.empty();

        std::cout << "PLAN FOUND (weight " << weight << "): "
                  << plannedPath.size() << " individual motions, "
                  << expansions << " expansions." << std::endl;
        return true;
    }

    return false;
}


// ============================================================
// START NEXT ROUND
// ============================================================

void SlidingCubeCode::startNextRound()
{
    if (!isLeader || moving)
        return;

    if (targetComplete())
    {
        std::cout << "TARGET COMPLETED. Total individual motions = "
                  << totalMotions << std::endl;
        return;
    }

    if (totalMotions >= MAX_MOTIONS)
    {
        std::cout << "STOP: maximum of " << MAX_MOTIONS
                  << " individual motions reached." << std::endl;
        return;
    }

    if (!planActive || plannedPathIndex >= plannedPath.size())
    {
        if (replanCount > 0 || plannedPath.empty())
            std::cout << "Planning from current configuration..."
                      << std::endl;

        if (replanCount > MAX_REPLANS || !buildPlan())
        {
            std::cout << "NO PLAN AVAILABLE - solver stopped."
                      << std::endl;
            return;
        }
        ++replanCount;

        if (!planActive)
        {
            std::cout << "TARGET COMPLETED. Total individual motions = "
                      << totalMotions << std::endl;
            return;
        }
    }

    const PlannedMove &planned = plannedPath[plannedPathIndex];

    if (lattice->getBlock(planned.from) == nullptr ||
        lattice->getBlock(planned.to) != nullptr)
    {
        std::cout << "Plan out of sync, re-planning." << std::endl;
        planActive = false;
        ++replanCount;
        if (replanCount > MAX_REPLANS)
        {
            std::cout << "NO PLAN AVAILABLE - solver stopped."
                      << std::endl;
            return;
        }
        startNextRound();
        return;
    }

    MoveToken token;
    token.sequence = currentRound;
    token.from = planned.from;
    token.to = planned.to;

    activeSequence = token.sequence;

    std::cout << "\nMOTION ROUND " << currentRound
              << " | STEP " << plannedPathIndex + 1
              << "/" << plannedPath.size()
              << " | " << token.from << " -> " << token.to
              << std::endl;

    if (module->position == token.from)
    {
        currentMove = token;
        executeCalculatedMove();
        return;
    }

    sendMessageToAllNeighbors(
        "Calculated movement",
        new MessageOf<MoveToken>(MOVE_MESSAGE, token),
        1000,
        0,
        0
    );
}


// ============================================================
// RECEIVE MOVE
// ============================================================

void SlidingCubeCode::receiveMove(
    std::shared_ptr<Message> message,
    P2PNetworkInterface *sender
)
{
    const MoveToken token =
        *std::static_pointer_cast<MessageOf<MoveToken>>(message)->getData();

    const std::string key = makeMoveTokenKey(token);

    if (receivedMoveTokens.find(key) != receivedMoveTokens.end())
        return;
    receivedMoveTokens.insert(key);

    sendMessageToAllNeighbors(
        "Relay movement",
        new MessageOf<MoveToken>(MOVE_MESSAGE, token),
        1000,
        0,
        1,
        sender
    );

    if (module->position != token.from)
        return;

    currentMove = token;
    currentRound = token.sequence;
    lastReceivedRound = token.sequence;
    executeCalculatedMove();
}


// ============================================================
// EXECUTE CALCULATED MOVE
// ============================================================

void SlidingCubeCode::executeCalculatedMove()
{
    if (moving)
        return;

    if (module->position != currentMove.from)
        return;

    if (currentMove.to[2] > 0 && targetsAreFloorOnly())
    {
        std::cout << "REJECTED: no stacking allowed: "
                  << currentMove.to << std::endl;
        movingRound = -1;
        sendResult(false);
        return;
    }

    if (lattice->getBlock(currentMove.to) != nullptr)
    {
        std::cout << "REJECTED: destination occupied: "
                  << currentMove.to << std::endl;
        movingRound = -1;
        sendResult(false);
        return;
    }

    if (!module->canMoveTo(currentMove.to))
    {
        std::cout << "REJECTED by VisibleSim: "
                  << currentMove.from << " -> " << currentMove.to
                  << std::endl;
        movingRound = -1;
        sendResult(false);
        return;
    }

    std::cout << "EXECUTING: " << currentMove.from
              << " -> " << currentMove.to << std::endl;

    moving = true;
    movingRound = currentMove.sequence;
    movingFrom = currentMove.from;

    setColor(RED);

    if (!module->moveTo(currentMove.to))
    {
        moving = false;
        std::cout << "ERROR: moveTo() failed." << std::endl;
        movingRound = -1;
        sendResult(false);
    }
}


// ============================================================
// MOTION END
// ============================================================

void SlidingCubeCode::onMotionEnd()
{
    if (!moving)
        return;

    moving = false;

    if (module->position != currentMove.to)
    {
        std::cout << "ERROR: motion ended at unexpected position."
                  << std::endl
                  << "Expected: " << currentMove.to << std::endl
                  << "Actual:   " << module->position << std::endl;
        sendResult(false);
        return;
    }

    std::cout << "MOTION FINISHED: " << movingFrom
              << " -> " << module->position << std::endl;

    if (target && target->isInTarget(module->position))
        setColor(GREEN);
    else
        setColor(GREY);

    sendResult(true);
}


// ============================================================
// RESULT KEY
// ============================================================

long long SlidingCubeCode::makeResultKey(
    int sequence,
    const Cell3DPosition &position
) const
{
    return static_cast<long long>(sequence) * 1000000LL
         + static_cast<long long>(position[0]) * 10000LL
         + static_cast<long long>(position[1]) * 100LL
         + static_cast<long long>(position[2]);
}


// ============================================================
// LEADER
// ============================================================

void SlidingCubeCode::handleResultAtLeader(const ResultToken &result)
{
    if (result.sequence != activeSequence)
        return;

    activeSequence = -1;

    if (result.success)
    {
        ++plannedPathIndex;
        ++totalMotions;
        std::cout << "Result OK for round " << result.sequence
                  << " | total motions = " << totalMotions << std::endl;
    }
    else
    {
        std::cout << "Leader: move failed: " << result.from
                  << " -> " << result.to << " (will re-plan)"
                  << std::endl;
        forbiddenMoves.insert(makeMoveKey(result.from, result.to));
        planActive = false;
    }

    ++currentRound;
    startNextRound();
}


// ============================================================
// SEND RESULT
// ============================================================

void SlidingCubeCode::sendResult(bool success)
{
    ResultToken result;

    result.sequence =
        movingRound >= 0 ? movingRound : currentMove.sequence;
    result.from =
        movingRound >= 0 ? movingFrom : currentMove.from;
    result.to = currentMove.to;
    result.success = success;

    if (isLeader)
    {
        const long long key =
            makeResultKey(result.sequence, result.from);

        if (processedResults.find(key) != processedResults.end())
            return;
        processedResults.insert(key);

        movingRound = -1;
        handleResultAtLeader(result);
        return;
    }

    movingRound = -1;

    sendMessageToAllNeighbors(
        "Movement result",
        new MessageOf<ResultToken>(RESULT_MESSAGE, result),
        1000,
        0,
        0
    );
}


// ============================================================
// RECEIVE RESULT
// ============================================================

void SlidingCubeCode::receiveResult(
    std::shared_ptr<Message> message,
    P2PNetworkInterface *sender
)
{
    const ResultToken result =
        *std::static_pointer_cast<MessageOf<ResultToken>>(message)->getData();

    const long long key = makeResultKey(result.sequence, result.from);

    if (processedResults.find(key) != processedResults.end())
        return;
    processedResults.insert(key);

    sendMessageToAllNeighbors(
        "Relay movement result",
        new MessageOf<ResultToken>(RESULT_MESSAGE, result),
        1000,
        0,
        1,
        sender
    );

    if (!isLeader)
        return;

    handleResultAtLeader(result);
}


// ============================================================
// TARGET COMPLETE?
// ============================================================

bool SlidingCubeCode::targetComplete() const
{
    if (!lattice || !target)
        return false;

    const std::vector<SlidingCubesBlock*> cubes = getAllCubes();
    const std::vector<Cell3DPosition> targets = getTargetCells();

    if (cubes.size() != targets.size())
        return false;

    for (const Cell3DPosition &p : targets)
        if (lattice->getBlock(p) == nullptr)
            return false;

    return true;
}


// ============================================================
// DRAWING CODE
// ============================================================

void SlidingCubeCode::onGlDraw()
{
    if (!lattice || !target)
        return;

    static const float thick = 0.8f;
    static const float color[4] = {2.2f, 0.2f, 0.2f, 1.0f};

    const Vector3D gl = lattice->gridScale;

    glPushAttrib(GL_ENABLE_BIT | GL_LIGHTING_BIT | GL_CURRENT_BIT);
    glDisable(GL_TEXTURE_2D);
    glMaterialfv(GL_FRONT, GL_AMBIENT_AND_DIFFUSE, color);

    auto b = lattice->getGridUpperBounds();

    for (int iz = 0; iz <= b[2]; iz++)
        for (int iy = 0; iy <= b[1]; iy++)
            for (int ix = 0; ix <= b[0]; ix++)
            {
                if (target->isInTarget(Cell3DPosition(ix, iy, iz)))
                {
                    glPushMatrix();
                    glNormal3f(0, 0, 1);
                    glScalef(gl[0], gl[1], gl[2]);
                    glTranslatef(ix, iy, iz - 0.49f);
                    glBegin(GL_QUAD_STRIP);

                    for (int i = 0; i <= 36; ++i)
                    {
                        double cs = 0.5 * std::cos(i * M_PI / 18);
                        double ss = 0.5 * std::sin(i * M_PI / 18);
                        glVertex3f(thick * cs, thick * ss, 0);
                        glVertex3f(cs, ss, 0);
                    }

                    glEnd();
                    glPopMatrix();
                }
            }

    glPopAttrib();
}