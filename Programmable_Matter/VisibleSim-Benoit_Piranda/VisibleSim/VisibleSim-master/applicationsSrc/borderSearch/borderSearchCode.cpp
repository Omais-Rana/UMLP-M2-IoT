#include "borderSearchCode.hpp"

#include "robots/blinkyBlocks/blinkyBlocksBlock.h"
#include "utils/color.h"

#include <cstdlib>
#include <iostream>
#include <limits>

using namespace std;
using namespace BaseSimulator;

namespace {

const char *directionName(int d) {
    switch (d) {
        case BorderSearchCode::FRONT:
            return "FRONT";
        case BorderSearchCode::RIGHT:
            return "RIGHT";
        case BorderSearchCode::BACK:
            return "BACK";
        case BorderSearchCode::LEFT:
            return "LEFT";
        default:
            return "?";
    }
}

} // namespace

BorderSearchCode::BorderSearchCode(
    BlinkyBlocks::BlinkyBlocksBlock *host)
    : BlinkyBlocks::BlinkyBlocksBlockCode(host) {

    addMessageEventFunc2(
        BORDER_SEARCH_MSG,
        [this](shared_ptr<Message> msg,
               P2PNetworkInterface *sender) {
            handleBorderSearch(msg, sender);
        });
}

Cell3DPosition BorderSearchCode::nextPosition(
    const Cell3DPosition &p,
    int dir) const {

    switch (dir) {
        case FRONT:
            return Cell3DPosition(p[0], p[1] - 1, p[2]);
        case RIGHT:
            return Cell3DPosition(p[0] + 1, p[1], p[2]);
        case BACK:
            return Cell3DPosition(p[0], p[1] + 1, p[2]);
        case LEFT:
            return Cell3DPosition(p[0] - 1, p[1], p[2]);
        default:
            return p;
    }
}

bool BorderSearchCode::isEmpty(
    const Cell3DPosition &p) const {

    if (!lattice->isInGrid(p)) {
        return true;
    }

    return lattice->isFree(p);
}

bool BorderSearchCode::isOnBorder() const {
    const Cell3DPosition p = hostBlock->position;

    for (int d = FRONT; d <= LEFT; ++d) {
        if (isEmpty(nextPosition(p, d))) {
            return true;
        }
    }

    return false;
}

bool BorderSearchCode::isInitiator() const {
    const Cell3DPosition p = hostBlock->position;

    const bool emptyFront =
        isEmpty(Cell3DPosition(p[0], p[1] - 1, p[2]));

    const bool emptyLeft =
        isEmpty(Cell3DPosition(p[0] - 1, p[1], p[2]));

    const bool occupiedFrontRight =
        !isEmpty(Cell3DPosition(p[0] + 1, p[1] - 1, p[2]));

    return emptyFront && (emptyLeft || occupiedFrontRight);
}

int BorderSearchCode::getNextDir(int prevDir) const {
    int k = (prevDir + 3) % 4;
    const Cell3DPosition p = hostBlock->position;

    for (int i = 0; i < 4; ++i) {
        if (!isEmpty(nextPosition(p, k))) {
            return k;
        }

        k = (k + 1) % 4;
    }

    return -1;
}

uint16_t BorderSearchCode::segmentLength(
    const Cell3DPosition &from,
    const Cell3DPosition &to,
    int dir) const {

    long long length = 0;

    if (dir == FRONT || dir == BACK) {
        length = llabs(
            static_cast<long long>(to[1]) -
            static_cast<long long>(from[1]));
    } else if (dir == RIGHT || dir == LEFT) {
        length = llabs(
            static_cast<long long>(to[0]) -
            static_cast<long long>(from[0]));
    }

    if (length <= 0) {
        return 0;
    }

    if (length >
        static_cast<long long>(numeric_limits<uint16_t>::max())) {
        return numeric_limits<uint16_t>::max();
    }

    return static_cast<uint16_t>(length);
}

void BorderSearchCode::printCompressedBorder(
    const BorderSearchData &data) const {

    cout << "  compressed border:" << endl;
    cout << "    start = " << data.startCorner << endl;

    for (const BorderSegment &segment : data.segments) {
        cout << "    "
             << directionName(static_cast<int>(segment.direction))
             << " x "
             << static_cast<unsigned int>(segment.length)
             << endl;
    }

    cout << "  representation elements = "
         << (1 + data.segments.size())
         << " (1 start corner + "
         << data.segments.size()
         << " segments)"
         << endl;
}

void BorderSearchCode::sendBorderSearch(
    int nextDir,
    const Cell3DPosition &initiatorPos,
    const Cell3DPosition &startCorner,
    const Cell3DPosition &currentCorner,
    const vector<BorderSegment> &segments) {

    const Cell3DPosition destination =
        nextPosition(hostBlock->position, nextDir);

    BlinkyBlocks::BlinkyBlocksBlock *bb =
        static_cast<BlinkyBlocks::BlinkyBlocksBlock *>(hostBlock);

    P2PNetworkInterface *dest =
        bb->getInterfaceToNeighborPos(destination);

    if (dest == nullptr || !dest->isConnected()) {
        cerr << "[BorderSearch] No connected neighbor at "
             << destination
             << " from " << hostBlock->position
             << " while following "
             << directionName(nextDir)
             << endl;
        return;
    }

    BorderSearchData data(
        nextDir,
        initiatorPos,
        startCorner,
        currentCorner,
        segments);

    Message *msg = new MessageOf<BorderSearchData>(
        BORDER_SEARCH_MSG,
        data);

    sendMessage(
        "BORDER SEARCH MSG",
        msg,
        dest,
        0,
        0);
}

void BorderSearchCode::handleBorderSearch(
    shared_ptr<Message> msg,
    P2PNetworkInterface *sender) {

    (void)sender;

    MessageOf<BorderSearchData> *typedMsg =
        dynamic_cast<MessageOf<BorderSearchData> *>(msg.get());

    if (typedMsg == nullptr) {
        cerr << "[BorderSearch] Invalid message payload." << endl;
        return;
    }

    const BorderSearchData *data = typedMsg->getData();

    if (data == nullptr) {
        return;
    }

    const Cell3DPosition currentPos = hostBlock->position;
    const Cell3DPosition &initiatorPos = data->initiatorPos;

    if (currentPos == initiatorPos) {
        vector<BorderSegment> closedSegments = data->segments;

        const uint16_t finalLength = segmentLength(
            data->currentCorner,
            currentPos,
            data->prevDir);

        if (finalLength > 0) {
            closedSegments.push_back(
                BorderSegment(data->prevDir, finalLength));
        }

        setColor(YELLOW);

        BorderSearchData result(
            data->prevDir,
            initiatorPos,
            data->startCorner,
            data->currentCorner,
            closedSegments);

        cout << "[BorderSearch] Border found by initiator "
             << initiatorPos
             << " | segments = "
             << closedSegments.size()
             << endl;

        printCompressedBorder(result);
        return;
    }

    map<int, Cell3DPosition>::const_iterator it =
        dirInit.find(data->prevDir);

    if (it != dirInit.end() &&
        it->second < initiatorPos) {
        return;
    }

    const int nextDir = getNextDir(data->prevDir);

    if (nextDir < 0) {
        cerr << "[BorderSearch] Dead end at "
             << currentPos
             << " for initiator "
             << initiatorPos
             << endl;
        return;
    }

    vector<BorderSegment> segments = data->segments;
    Cell3DPosition currentCorner = data->currentCorner;

    const bool isCorner =
        (nextDir != data->prevDir) && isOnBorder();

    if (isCorner) {
        const uint16_t length = segmentLength(
            data->currentCorner,
            currentPos,
            data->prevDir);

        if (length > 0) {
            segments.push_back(
                BorderSegment(data->prevDir, length));
        }

        currentCorner = currentPos;

        /*
         * Color priority:
         *   initiator = YELLOW
         *   corner    = ORANGE
         *   path      = GREEN
         */
        setColor(ORANGE);
    } else if (currentPos != initiatorPos) {
        setColor(GREEN);
    }

    dirInit[data->prevDir] = initiatorPos;

    sendBorderSearch(
        nextDir,
        initiatorPos,
        data->startCorner,
        currentCorner,
        segments);
}

void BorderSearchCode::startup() {
    const Cell3DPosition p = hostBlock->position;

    if (!isInitiator()) {
        return;
    }

    setColor(YELLOW);

    const int nextDir = getNextDir(FRONT);

    if (nextDir < 0) {
        cout << "[BorderSearch] Initiator "
             << p
             << " has no occupied neighbor."
             << endl;
        return;
    }

    vector<BorderSegment> segments;

    cout << "[BorderSearch] Initiator "
         << p
         << " starts in direction "
         << directionName(nextDir)
         << endl;

    sendBorderSearch(
        nextDir,
        p,
        p,
        p,
        segments);
}

BaseSimulator::BlockCode *
BorderSearchCode::buildNewBlockCode(
    BaseSimulator::BuildingBlock *host) {

    return new BorderSearchCode(
        static_cast<BlinkyBlocks::BlinkyBlocksBlock *>(host));
}
