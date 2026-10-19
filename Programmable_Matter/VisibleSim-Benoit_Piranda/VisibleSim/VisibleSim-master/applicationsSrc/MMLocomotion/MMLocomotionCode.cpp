#include "MMLocomotionCode.hpp"

namespace {
std::map<Cell3DPosition, int> g_reservedCells;
}

MMLocomotionCode::MMLocomotionCode(Catoms3DBlock *host)
    : Catoms3DBlockCode(host), module(host) {
    if (not host) return;

    addMessageEventFunc2(TOKEN_MSG_ID, std::bind(&MMLocomotionCode::onMoveToken, this, std::placeholders::_1, std::placeholders::_2));
    addMessageEventFunc2(RESET_MSG_ID, std::bind(&MMLocomotionCode::onCycleReset, this, std::placeholders::_1, std::placeholders::_2));
}

//  Shape Helpers
const std::array<Cell3DPosition, 12>& MMLocomotionCode::cellsOf(MMShape s) {
    return (s == MMShape::FB) ? FB_RELATIVE_POSITIONS : BF_RELATIVE_POSITIONS;
}

MMShape MMLocomotionCode::opposite(MMShape s) {
    return (s == MMShape::FB) ? MMShape::BF : MMShape::FB;
}

bool MMLocomotionCode::isComplete(const Cell3DPosition &root, MMShape s) {
    for (const auto &rel : cellsOf(s)) {
        Cell3DPosition p = root + rel;
        if (!lattice->isInGrid(p) || !lattice->getBlock(p)) return false;
    }
    return true;
}

bool MMLocomotionCode::detectRoot(const Cell3DPosition &p, MMShape &s) {
    if (isComplete(p, MMShape::FB)) { s = MMShape::FB; return true; }
    if (isComplete(p, MMShape::BF)) { s = MMShape::BF; return true; }
    return false;
}

bool MMLocomotionCode::hasSupport(const Cell3DPosition &p) {
    return !lattice->getActiveNeighborCells(p).empty();
}

int MMLocomotionCode::randomBetween(int lo, int hi) {
    if (hi <= lo) return lo;
    return std::uniform_int_distribution<int>(lo, hi)(randomGenerator);
}

int MMLocomotionCode::getArrIdx(int curIdx, bool movingRight) {
    return movingRight ? curIdx : 11 - curIdx;
}

//  Distributed Spatial Pipelining Logic
void MMLocomotionCode::startup() {
    checkInitiator(0, true);
}

void MMLocomotionCode::checkInitiator(int cycleSeq, bool movingRight) {
    MMShape s;
    if (!detectRoot(module->position, s)) return;

    int dir = movingRight ? 1 : -1;
    Cell3DPosition back(module->position[0] - dir * MM_SPACING_X, module->position[1], module->position[2]);
    if (isComplete(back, opposite(s))) return;

    Cell3DPosition head = module->position;
    MMShape headShape = s;
    while (true) {
        Cell3DPosition next(head[0] + dir * MM_SPACING_X, head[1], head[2]);
        if (!isComplete(next, opposite(headShape))) break;
        head = next;
        headShape = opposite(headShape);
    }

    Cell3DPosition newRef(head[0] + dir * MM_SPACING_X, head[1], head[2]);
    MMShape newShape = opposite(headShape);

    bool fits = true;
    for (const auto &rel : cellsOf(newShape)) {
        if (!lattice->isInGrid(newRef + rel)) { fits = false; break; }
    }

    if (!fits) {
        MM_LOG("Grid border reached! Reversing chain direction.");
        movingRight = !movingRight;
        CycleReset cr = { cycleSeq + 1, movingRight };
        seenResets.insert(cr.cycleSeq);
        for (auto p2p : module->getP2PNetworkInterfaces()) {
            if (p2p->connectedInterface) sendMessage("reset", new MessageOf<CycleReset>(RESET_MSG_ID, cr), p2p, 50, 10);
        }
        checkInitiator(cr.cycleSeq, cr.movingRight);
        return;
    }

    MoveToken t;
    t.cycleSeq = cycleSeq;
    t.curIdx = 0;
    t.movingRight = movingRight;
    t.srcRef = module->position;
    t.newRef = newRef;
    t.srcShape = s;
    t.newShape = newShape;

    int arrIdx = getArrIdx(t.curIdx, movingRight);
    t.targetPos = t.srcRef + cellsOf(t.srcShape)[arrIdx];
    t.goalPos = t.newRef + cellsOf(t.newShape)[arrIdx];

    MM_LOG("Cycle " << cycleSeq << " Initiator: Dismantling " << t.srcRef << " -> " << t.newRef);
    seenTokens.insert({t.cycleSeq, t.curIdx});

    // We only send the token IF we aren't the target ourselves.
    if (module->position == t.targetPos) {
        executeToken(t);
    } else {
        for (auto p2p : module->getP2PNetworkInterfaces()) {
            if (p2p->connectedInterface) sendMessage("token", new MessageOf<MoveToken>(TOKEN_MSG_ID, t), p2p, 50, 10);
        }
    }
}

void MMLocomotionCode::onMoveToken(std::shared_ptr<Message> msg, P2PNetworkInterface *sender) {
    MoveToken t = *static_cast<MessageOf<MoveToken>*>(msg.get())->getData();

    std::pair<int, int> tokenKey = {t.cycleSeq, t.curIdx};
    if (seenTokens.count(tokenKey)) return;
    seenTokens.insert(tokenKey);

    for (auto p2p : module->getP2PNetworkInterfaces()) {
        if (p2p != sender && p2p->connectedInterface) sendMessage("token", new MessageOf<MoveToken>(TOKEN_MSG_ID, t), p2p, 50, 10);
    }

    if (module->position == t.targetPos && !moving) executeToken(t);
}

void MMLocomotionCode::onCycleReset(std::shared_ptr<Message> msg, P2PNetworkInterface *sender) {
    CycleReset cr = *static_cast<MessageOf<CycleReset>*>(msg.get())->getData();
    if (seenResets.count(cr.cycleSeq)) return;
    seenResets.insert(cr.cycleSeq);

    for (auto p2p : module->getP2PNetworkInterfaces()) {
        if (p2p != sender && p2p->connectedInterface) sendMessage("reset", new MessageOf<CycleReset>(RESET_MSG_ID, cr), p2p, 50, 10);
    }

    seenTokens.clear();
    checkInitiator(cr.cycleSeq, cr.movingRight);
}

//  Conveyor Belt Logic
void MMLocomotionCode::executeToken(const MoveToken &t) {
    myToken = t;
    moving = true;
    tokenPassed = false;
    goal = myToken.goalPos;
    blockedCount = 0;
    replanCount = 0;

    MM_LOG("catom " << getId() << " starting sequence " << myToken.curIdx);

    TimerData td;
    td.kind = TIMER_START_MOVE;
    getScheduler()->schedule(new InterruptionEvent<TimerData>(getScheduler()->now() + randomBetween(0, START_JITTER_MAX), module, td));
}

void MMLocomotionCode::passTokenToNext() {
    if (!tokenPassed && myToken.curIdx < 11) {
        tokenPassed = true;
        MoveToken nextT = myToken;
        nextT.curIdx++;
        int nextArrIdx = getArrIdx(nextT.curIdx, nextT.movingRight);
        nextT.targetPos = nextT.srcRef + cellsOf(nextT.srcShape)[nextArrIdx];
        nextT.goalPos = nextT.newRef + cellsOf(nextT.newShape)[nextArrIdx];

        seenTokens.insert({nextT.cycleSeq, nextT.curIdx});
        for (auto p2p : module->getP2PNetworkInterfaces()) {
            if (p2p->connectedInterface) sendMessage("token", new MessageOf<MoveToken>(TOKEN_MSG_ID, nextT), p2p, 50, 10);
        }
    }
}

void MMLocomotionCode::onInterruptionEvent(std::shared_ptr<Event> event) {
    auto ev = std::static_pointer_cast<InterruptionEvent<TimerData>>(event);
    TimerData t = ev->data;
    switch (t.kind) {
        case TIMER_START_MOVE: replan(); break;
        case TIMER_STEP_RETRY: if (moving) scheduleStep(); break;
        case TIMER_REPLAN:     if (moving) replan(); break;
    }
}

void MMLocomotionCode::replan() {
    blockedCount = 0;
    path = findPath(module->position, goal);
    stepIdx = 0;

    if (path.empty()) {
        retryLater("Waiting for kinematic clearance...");
        return;
    }
    scheduleStep();
}

void MMLocomotionCode::retryLater(const char *reason) {
    TimerData t;
    t.kind = TIMER_REPLAN;
    getScheduler()->schedule(new InterruptionEvent<TimerData>(getScheduler()->now() + randomBetween(LOCAL_RETRY_MIN, LOCAL_RETRY_MAX), module, t));
}

void MMLocomotionCode::reserveCell(const Cell3DPosition &c) {
    g_reservedCells[c] = static_cast<int>(getId());
    reservedCell = c;
    hasReservation = true;
}

void MMLocomotionCode::releaseReservation() {
    if (!hasReservation) return;
    auto it = g_reservedCells.find(reservedCell);
    if (it != g_reservedCells.end() && it->second == static_cast<int>(getId())) {
        g_reservedCells.erase(it);
    }
    hasReservation = false;
}

void MMLocomotionCode::scheduleStep() {
    if (stepIdx >= path.size()) {
        replan();
        return;
    }
    const Cell3DPosition next = path[stepIdx];

    bool blocked = false;
    if (lattice->getBlock(next)) blocked = true;
    auto res = g_reservedCells.find(next);
    if (res != g_reservedCells.end() && res->second != static_cast<int>(getId())) blocked = true;
    if (!blocked && !module->canMoveTo(next)) blocked = true;

    if (blocked) {
        backoffOrReplan();
        return;
    }

    blockedCount = 0;
    reserveCell(next);
    getScheduler()->schedule(new Catoms3DRotationStartEvent(getScheduler()->now() + 100 + randomBetween(0, STEP_JITTER_MAX), module, next));
}

void MMLocomotionCode::backoffOrReplan() {
    if (++blockedCount <= MAX_BACKOFFS) {
        TimerData t;
        t.kind = TIMER_STEP_RETRY;
        getScheduler()->schedule(new InterruptionEvent<TimerData>(getScheduler()->now() + randomBetween(BACKOFF_MIN, BACKOFF_MAX), module, t));
        return;
    }
    blockedCount = 0;
    retryLater("Path blocked during transit");
}

void MMLocomotionCode::onMotionEnd() {
    releaseReservation();
    if (!moving) return;

    // PIPELINE LOGIC: Once we have traveled SAFE_DEPARTURE_STEPS away from the source,
    // we physically clear the departure zone. We safely invite the next node to begin.
    if (!tokenPassed && (stepIdx >= SAFE_DEPARTURE_STEPS || stepIdx >= path.size() - 1)) {
        passTokenToNext();
    }

    if (module->position == goal) {
        finishMove();
        return;
    }

    if (stepIdx < path.size() && module->position == path[stepIdx]) {
        stepIdx++;
        blockedCount = 0;
        replanCount = 0;
        if (stepIdx < path.size()) {
            scheduleStep();
            return;
        }
        replan();
        return;
    }

    if (++replanCount > MAX_REPLANS) {
        replanCount = 0;
        retryLater("lost");
        return;
    }
    replan();
}

void MMLocomotionCode::finishMove() {
    moving = false;
    MM_LOG("catom " << getId() << " successfully assembled at " << module->position);

    // Failsafe: if the path was incredibly short, pass token upon completion
    if (!tokenPassed) passTokenToNext();

    if (myToken.curIdx == 11) {
        CycleReset cr = { myToken.cycleSeq + 1, myToken.movingRight };
        seenResets.insert(cr.cycleSeq);
        for (auto p2p : module->getP2PNetworkInterfaces()) {
            if (p2p->connectedInterface) sendMessage("reset", new MessageOf<CycleReset>(RESET_MSG_ID, cr), p2p, 50, 10);
        }
        seenTokens.clear();
        checkInitiator(cr.cycleSeq, cr.movingRight);
    }
}

vector<Cell3DPosition> MMLocomotionCode::findPath(Cell3DPosition &start, Cell3DPosition &goal) {
    vector<Cell3DPosition> path;
    set<Cell3DPosition> visited;
    map<Cell3DPosition, Cell3DPosition> parentMap;
    queue<Cell3DPosition> q;
    q.push(start);
    visited.insert(start);
    bool found = false;
    size_t expanded = 0;

    while (!q.empty() and !found) {
        Cell3DPosition current = q.front();
        q.pop();
        if (current == goal) {
            found = true;
            break;
        }
        if (++expanded > MAX_BFS_NODES) break;
        vector<Cell3DPosition> reachablePositions;

        if (getAllPossibleMotionsFromPosition(current, reachablePositions)) {
            for (auto &pos : reachablePositions) {
                if (pos != goal && lattice->getBlock(pos)) continue;
                if (visited.find(pos) == visited.end()) {
                    visited.insert(pos);
                    parentMap[pos] = current;
                    q.push(pos);
                }
            }
        }
    }

    if (found) {
        Cell3DPosition step = goal;
        while (step != start) {
            path.push_back(step);
            step = parentMap[step];
        }
        reverse(path.begin(), path.end());
    }

    return path;
}

bool MMLocomotionCode::getAllPossibleMotionsFromPosition(Cell3DPosition position, vector<Cell3DPosition> &reachablePositions) {
    Catoms3DBlock *mod = static_cast<Catoms3DBlock *>(lattice->getBlock(position));
    if (mod) {
        for (auto &neighPos : lattice->getFreeNeighborCells(position)) {
            if (mod->canMoveTo(neighPos) && (!lattice->getBlock(neighPos) || neighPos == position)) {
                reachablePositions.push_back(neighPos);
            }
        }
        return !reachablePositions.empty();
    }
    bool found = false;
    for (auto &neighPos : lattice->getActiveNeighborCells(position)) {
        Catoms3DBlock *neigh = static_cast<Catoms3DBlock *>(lattice->getBlock(neighPos));
        vector<Catoms3DMotionRulesLink *> vec;
        Catoms3DMotionRules motionRulesInstance;
        short conFrom = neigh->getConnectorId(position);
        motionRulesInstance.getValidMotionListFromPivot(neigh, conFrom, vec, static_cast<FCCLattice *>(lattice), NULL);
        for (auto link : vec) {
            Cell3DPosition toPos;
            neigh->getNeighborPos(link->getConToID(), toPos);
            if (!lattice->getBlock(toPos) || toPos == position) {
                reachablePositions.push_back(toPos);
                found = true;
            }
        }
    }
    return found;
}