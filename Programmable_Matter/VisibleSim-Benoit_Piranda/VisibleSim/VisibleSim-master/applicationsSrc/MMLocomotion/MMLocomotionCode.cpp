#include "MMLocomotionCode.hpp"

MMLocomotionCode::MMLocomotionCode(Catoms3DBlock *host)
    : Catoms3DBlockCode(host), module(host) {
    if (not host) return;

    addMessageEventFunc2(MOVE_CMD_MSG_ID,
        std::bind(&MMLocomotionCode::onMoveCmd, this,
                  std::placeholders::_1, std::placeholders::_2));
    addMessageEventFunc2(MOVE_DONE_MSG_ID,
        std::bind(&MMLocomotionCode::onMoveDone, this,
                  std::placeholders::_1, std::placeholders::_2));
}

/* ------------------------------------------------------------------ */
/*  Shape helpers                                                      */
/* ------------------------------------------------------------------ */

const std::array<Cell3DPosition, 12>& MMLocomotionCode::cellsOf(MMShape s) {
    return (s == MMShape::FB) ? FB_RELATIVE_POSITIONS : BF_RELATIVE_POSITIONS;
}

MMShape MMLocomotionCode::opposite(MMShape s) {
    return (s == MMShape::FB) ? MMShape::BF : MMShape::FB;
}

// true if all 12 cells of a meta-module of shape s rooted at 'root' hold a module
bool MMLocomotionCode::isComplete(const Cell3DPosition &root, MMShape s) {
    for (const auto &rel : cellsOf(s)) {
        Cell3DPosition p = root + rel;
        if (!lattice->isInGrid(p) || !lattice->getBlock(p)) return false;
    }
    return true;
}

// is p the root (0,0,0) of a complete meta-module? if so return its shape
bool MMLocomotionCode::detectRoot(const Cell3DPosition &p, MMShape &s) {
    if (isComplete(p, MMShape::FB)) { s = MMShape::FB; return true; }
    if (isComplete(p, MMShape::BF)) { s = MMShape::BF; return true; }
    return false;
}

/* ------------------------------------------------------------------ */
/*  Startup: the root of the left-most meta-module becomes coordinator */
/* ------------------------------------------------------------------ */

void MMLocomotionCode::startup() {
    console << "start " << getId() << "\n";

    MMShape s;
    const Cell3DPosition p = module->position;
    if (!detectRoot(p, s)) return;                       // not a meta-module root

    // is there a meta-module (of the opposite shape) on my left?  then I'm not left-most
    Cell3DPosition left(p[0] - MM_SPACING_X, p[1], p[2]);
    if (isComplete(left, opposite(s))) return;

    // I am the root of the left-most meta-module -> coordinator
    isCoordinator = true;
    srcRef = p;
    srcShape = s;

    // walk right along the chain to find the right-most meta-module
    Cell3DPosition last = p;
    MMShape lastShape = s;
    while (true) {
        Cell3DPosition next(last[0] + MM_SPACING_X, last[1], last[2]);
        if (!isComplete(next, opposite(lastShape))) break;
        last = next;
        lastShape = opposite(lastShape);
    }
    newRef = Cell3DPosition(last[0] + MM_SPACING_X, last[1], last[2]);
    newShape = opposite(lastShape);                      // chain alternates FB / BF

    console << "Coordinator: dismantle meta-module at " << srcRef
            << ", rebuild at " << newRef << "\n";
    setColor(RED);
    curIdx = 0;
    sendNextCommand();
}

/* ------------------------------------------------------------------ */
/*  Coordinator: one module moves at a time                            */
/* ------------------------------------------------------------------ */

void MMLocomotionCode::sendNextCommand() {
    if (curIdx >= 12) {
        console << "Meta-module moved ^-^\n";
        return;
    }
    MoveCmd c;
    c.seq  = curIdx;
    c.from = srcRef + cellsOf(srcShape)[curIdx];
    c.to   = newRef + cellsOf(newShape)[curIdx];

    seenCmd.insert(c.seq);
    if (c.from == module->position) {
        startMove(c);                                    // the coordinator itself must move
    } else {
        floodCmd(c, nullptr);
    }
}

void MMLocomotionCode::floodCmd(const MoveCmd &c, P2PNetworkInterface *except) {
    for (P2PNetworkInterface *p2p : module->getP2PNetworkInterfaces()) {
        if (p2p == except || !p2p->connectedInterface) continue;
        sendMessage("moveCmd", new MessageOf<MoveCmd>(MOVE_CMD_MSG_ID, c), p2p, 100, 10);
    }
}

void MMLocomotionCode::floodDone(const MoveDone &d, P2PNetworkInterface *except) {
    for (P2PNetworkInterface *p2p : module->getP2PNetworkInterfaces()) {
        if (p2p == except || !p2p->connectedInterface) continue;
        sendMessage("moveDone", new MessageOf<MoveDone>(MOVE_DONE_MSG_ID, d), p2p, 100, 10);
    }
}

void MMLocomotionCode::onMoveCmd(std::shared_ptr<Message> msg, P2PNetworkInterface *sender) {
    MessageOf<MoveCmd> *m = static_cast<MessageOf<MoveCmd>*>(msg.get());
    MoveCmd c = *m->getData();

    if (!seenCmd.insert(c.seq).second) return;           // already handled
    floodCmd(c, sender);

    if (module->position == c.from && !moving) startMove(c);
}

void MMLocomotionCode::onMoveDone(std::shared_ptr<Message> msg, P2PNetworkInterface *sender) {
    MessageOf<MoveDone> *m = static_cast<MessageOf<MoveDone>*>(msg.get());
    MoveDone d = *m->getData();

    if (!seenDone.insert(d.seq).second) return;
    floodDone(d, sender);

    if (isCoordinator && d.seq == curIdx) {
        curIdx++;
        sendNextCommand();
    }
}

/* ------------------------------------------------------------------ */
/*  Mover: follow a BFS path with one rotation per step                */
/* ------------------------------------------------------------------ */

void MMLocomotionCode::startMove(const MoveCmd &c) {
    moving = true;
    moveSeq = c.seq;
    goal = c.to;

    Cell3DPosition start = module->position;
    path = findPath(start, goal);
    if (path.empty()) {
        console << "ERROR: no path from " << start << " to " << goal << "\n";
        moving = false;
        return;
    }
    stepIdx = 0;
    scheduleStep();
}

void MMLocomotionCode::scheduleStep() {
    Cell3DPosition nextPosition = path[stepIdx];
    getScheduler()->schedule(
        new Catoms3DRotationStartEvent(getScheduler()->now() + 100, module, nextPosition));
}

void MMLocomotionCode::onMotionEnd() {
    if (!moving) return;

    if (module->position == goal) {
        finishMove();
        return;
    }

    // normal case: we landed where the path said, go on with the next step
    if (stepIdx < path.size() && module->position == path[stepIdx]) {
        stepIdx++;
        if (stepIdx < path.size() && module->canMoveTo(path[stepIdx])) {
            scheduleStep();
            return;
        }
    }

    // otherwise: replan from where we are
    Cell3DPosition start = module->position;
    path = findPath(start, goal);
    stepIdx = 0;
    if (path.empty()) {
        console << "ERROR: lost, no path from " << start << " to " << goal << "\n";
        moving = false;
        return;
    }
    scheduleStep();
}

void MMLocomotionCode::finishMove() {
    moving = false;
    console << "module " << moveSeq << " placed at " << module->position << "\n";

    if (isCoordinator) {
        curIdx++;
        sendNextCommand();
    } else {
        MoveDone d;
        d.seq = moveSeq;
        seenDone.insert(d.seq);
        floodDone(d, nullptr);
    }
}

/* ------------------------------------------------------------------ */
/*  Path finding (given in the exercise sheet)                         */
/* ------------------------------------------------------------------ */

vector<Cell3DPosition> MMLocomotionCode::findPath(Cell3DPosition &start, Cell3DPosition &goal) {
    // BFS that returns the path from start to goal and uses getAllPossibleMotionsFromPosition
    vector<Cell3DPosition> path;
    set<Cell3DPosition> visited;
    map<Cell3DPosition, Cell3DPosition> parentMap;
    queue<Cell3DPosition> q;
    q.push(start);
    visited.insert(start);
    bool found = false;
    while (!q.empty() and !found) {
        Cell3DPosition current = q.front();
        q.pop();
        if (current == goal) {
            found = true;
            break;
        }
        vector<Cell3DPosition> reachablePositions;
        bool success = getAllPossibleMotionsFromPosition(current, reachablePositions);
        if (success) {
            for (auto &pos : reachablePositions) {
                if (visited.find(pos) == visited.end()) {
                    visited.insert(pos);
                    parentMap[pos] = current;
                    q.push(pos);
                }
            }
        }
    }
    if (found) {
        cout << "Found path from " << start << " to " << goal << "\n";
        Cell3DPosition step = goal;
        while (step != start) {
            path.push_back(step);
            step = parentMap[step];
        }
        reverse(path.begin(), path.end());
    } else {
        cout << "No path found from " << start << " to " << goal << "\n";
    }
    return path;
}

bool MMLocomotionCode::getAllPossibleMotionsFromPosition(
    // get all reachable positions from any given position (used in findPath)
    Cell3DPosition position,
    vector<Cell3DPosition> &reachablePositions) {
    Catoms3DBlock *mod = static_cast<Catoms3DBlock *>(lattice->getBlock(position));
    if (mod) {
        for (auto &neighPos : lattice->getFreeNeighborCells(position)) {
            if (mod->canMoveTo(neighPos)) {
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
        Cell3DPosition pos;
        short conFrom = neigh->getConnectorId(position);
        motionRulesInstance.getValidMotionListFromPivot(neigh, conFrom, vec,
                                                        static_cast<FCCLattice *>(lattice), NULL);
        for (auto link : vec) {
            Cell3DPosition toPos;
            neigh->getNeighborPos(link->getConToID(), toPos);
            reachablePositions.push_back(toPos);
            found = true;
        }
    }
    return found;
}