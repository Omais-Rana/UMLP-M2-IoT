#include "boxesCode.hpp"

#include "robots/blinkyBlocks/blinkyBlocksBlock.h"
#include "utils/color.h"

#include <iostream>

using namespace std;
using namespace BaseSimulator;

vector<BoxesCode::Box> BoxesCode::allBoxes;
unsigned long BoxesCode::messageCount = 0;

namespace {

const float PALETTE[][3] = {
    {0.55f, 0.80f, 0.25f}, {1.00f, 0.90f, 0.00f}, {0.20f, 0.65f, 0.90f},
    {0.95f, 0.30f, 0.60f}, {1.00f, 0.65f, 0.20f}, {0.65f, 0.55f, 0.85f},
    {0.00f, 0.80f, 0.80f}, {0.80f, 0.10f, 0.10f}, {0.60f, 0.40f, 0.25f},
    {0.60f, 0.70f, 0.95f}
};
const int PALETTE_SIZE = sizeof(PALETTE) / sizeof(PALETTE[0]);

bool inside(const BoxesCode::Box &a, const BoxesCode::Box &b) { // a inside b
    for (int i = 0; i < 3; ++i) {
        if (a.cmin[i] < b.cmin[i] || a.cmax[i] > b.cmax[i]) return false;
    }
    return true;
}

}

BoxesCode::BoxesCode(BlinkyBlocks::BlinkyBlocksBlock *host)
    : BlinkyBlocks::BlinkyBlocksBlockCode(host),
      d(0), w(0), dLeftKnown(false), dLeft(0),
      rminDecided(false), isRmin(false), boxReady(false),
      belowEmpty(true), belowKnown(false), belowRmin(false),
      belowD(0), belowW(0), cminDecided(false), isCmin(false) {

    struct Reg { int type; void (BoxesCode::*fn)(const BoxData &); };
    const Reg regs[] = {
        {SET_D_MSG,      &BoxesCode::onSetD},
        {D_INFO_MSG,     &BoxesCode::onDInfo},
        {FIND_W_MSG,     &BoxesCode::onFindW},
        {SET_W_MSG,      &BoxesCode::onSetW},
        {INFO_UP_MSG,    &BoxesCode::onInfoUp},
        {HEIGHT_MSG,     &BoxesCode::onHeight},
        {HEIGHT_REP_MSG, &BoxesCode::onHeightRep}
    };

    for (const Reg &r : regs) {
        void (BoxesCode::*fn)(const BoxData &) = r.fn;
        addMessageEventFunc2(
            r.type,
            [this, fn](shared_ptr<Message> msg, P2PNetworkInterface *) {
                MessageOf<BoxData> *typed =
                    dynamic_cast<MessageOf<BoxData> *>(msg.get());
                if (typed == nullptr || typed->getData() == nullptr) {
                    cerr << "[Boxes] Invalid message payload." << endl;
                    return;
                }
                const BoxData data = *typed->getData(); // copy
                (this->*fn)(data);
            });
    }
}

// helpers

Cell3DPosition BoxesCode::at(int dx, int dy, int dz) const {
    const Cell3DPosition p = hostBlock->position;
    return Cell3DPosition(p[0] + dx, p[1] + dy, p[2] + dz);
}

bool BoxesCode::isEmpty(const Cell3DPosition &p) const {
    if (!lattice->isInGrid(p)) return true;
    return lattice->isFree(p);
}

void BoxesCode::sendTo(const Cell3DPosition &target, int type, const BoxData &data) {
    BlinkyBlocks::BlinkyBlocksBlock *bb =
        static_cast<BlinkyBlocks::BlinkyBlocksBlock *>(hostBlock);

    P2PNetworkInterface *dest = bb->getInterfaceToNeighborPos(target);

    if (dest == nullptr || !dest->isConnected()) {
        cerr << "[Boxes] No connected neighbor at " << target
             << " from " << hostBlock->position
             << " (msg type " << type << ")" << endl;
        return;
    }

    Message *msg = new MessageOf<BoxData>(type, data);
    ++messageCount;
    sendMessage("BOXES MSG", msg, dest, 0, 0);
}

// Getting distance d

void BoxesCode::startup() {
    const Cell3DPosition p = hostBlock->position;
    (void)p;

    belowEmpty = isEmpty(at(0, 0, -1));

    if (isEmpty(at(-1, 0, 0))) {
        dLeftKnown = true;
        dLeft = 0;
    }

    if (isEmpty(at(0, 1, 0))) {
        setD(1);
    }
}

void BoxesCode::setD(int value) {
    d = value;

    if (!isEmpty(at(0, -1, 0))) {
        BoxData m;
        m.d = d;
        sendTo(at(0, -1, 0), SET_D_MSG, m);
    }

    if (!isEmpty(at(1, 0, 0))) {
        BoxData m;
        m.d = d;
        sendTo(at(1, 0, 0), D_INFO_MSG, m);
    }

    tryRmin();

    vector<BoxData> pend;
    pend.swap(pendingFind);
    for (const BoxData &m : pend) processFind(m);
}

void BoxesCode::onSetD(const BoxData &data) {
    setD(data.d + 1);
}

void BoxesCode::onDInfo(const BoxData &data) {
    dLeft = data.d;
    dLeftKnown = true;
    tryRmin();
}

/* Rmin election + width w */

void BoxesCode::tryRmin() {
    if (rminDecided || d == 0 || !dLeftKnown) return;
    rminDecided = true;

    const bool emptyFront = isEmpty(at(0, -1, 0));
    const bool emptyLeftFront = isEmpty(at(-1, -1, 0));

    // Eq. (2): empty(front) && (d != d_left || !empty(left + front))
    isRmin = emptyFront && (d != dLeft || !emptyLeftFront);

    if (!isRmin) {
        markReady();
        return;
    }

    if (isEmpty(at(1, 0, 0))) {
        w = 1;
        finishW();
    } else {
        BoxData m;
        m.origin = hostBlock->position;
        m.d = d;
        sendTo(at(1, 0, 0), FIND_W_MSG, m);
    }
}

void BoxesCode::onFindW(const BoxData &data) {
    if (d == 0) {                 // d not set yet: keep the message
        pendingFind.push_back(data);
        return;
    }
    processFind(data);
}

void BoxesCode::processFind(const BoxData &data) {
    BoxData reply;
    reply.origin = data.origin;
    reply.d = data.d;

    if (d < data.d) {                       // line too short: stop, w = 0
        reply.w = 0;
        sendTo(at(-1, 0, 0), SET_W_MSG, reply);
    } else if (isEmpty(at(1, 0, 0))) {      // end of row: count starts at 1
        reply.w = 1;
        sendTo(at(-1, 0, 0), SET_W_MSG, reply);
    } else {                                // keep looking to the right
        sendTo(at(1, 0, 0), FIND_W_MSG, data);
    }
}

void BoxesCode::onSetW(const BoxData &data) {
    if (data.origin == hostBlock->position) {
        w = data.w + 1;
        finishW();
    } else {
        BoxData m = data;
        m.w = data.w + 1;
        sendTo(at(-1, 0, 0), SET_W_MSG, m);
    }
}

void BoxesCode::finishW() {
    markReady();
}

/* Cmin election (along z) */

void BoxesCode::markReady() {
    boxReady = true;
    sendInfoUp();

    vector<BoxData> pend;
    pend.swap(pendingHeight);
    for (const BoxData &m : pend) processHeight(m);

    tryCmin();
}

void BoxesCode::sendInfoUp() {
    if (isEmpty(at(0, 0, 1))) return;
    BoxData m;
    m.flag = isRmin;
    m.d = isRmin ? d : 0;
    m.w = isRmin ? w : 0;
    sendTo(at(0, 0, 1), INFO_UP_MSG, m);
}

void BoxesCode::onInfoUp(const BoxData &data) {
    belowKnown = true;
    belowRmin = data.flag;
    belowD = data.d;
    belowW = data.w;
    tryCmin();
}

void BoxesCode::tryCmin() {
    if (cminDecided || !boxReady || !isRmin) return;
    if (!belowEmpty && !belowKnown) return;
    cminDecided = true;

    // Eq. (4): Rmin && !(Rmin(below) && d == d_below && w == w_below)
    isCmin = !(!belowEmpty && belowRmin && d == belowD && w == belowW);

    if (!isCmin) {
        setColor(Color(0.3f, 0.3f, 0.3f)); // absorbed by the box below
        return;
    }

    // Stage 4: find the height of the box.
    if (isEmpty(at(0, 0, 1))) {
        finalizeBox(1);
    } else {
        BoxData m;
        m.origin = hostBlock->position;
        m.d = d;
        m.w = w;
        m.h = 1;
        sendTo(at(0, 0, 1), HEIGHT_MSG, m);
    }
}

// Box height

void BoxesCode::onHeight(const BoxData &data) {
    if (!boxReady) {
        pendingHeight.push_back(data);
        return;
    }
    processHeight(data);
}

void BoxesCode::processHeight(const BoxData &data) {
    BoxData m = data;

    if (isRmin && w == data.w && d == data.d) {
        // Same rectangle on this layer: the box grows by one layer.
        m.h = data.h + 1;
        if (isEmpty(at(0, 0, 1))) {
            sendTo(at(0, 0, -1), HEIGHT_REP_MSG, m);
        } else {
            sendTo(at(0, 0, 1), HEIGHT_MSG, m);
        }
    } else {
        sendTo(at(0, 0, -1), HEIGHT_REP_MSG, m);
    }
}

void BoxesCode::onHeightRep(const BoxData &data) {
    if (data.origin == hostBlock->position) {
        finalizeBox(data.h);
    } else {
        sendTo(at(0, 0, -1), HEIGHT_REP_MSG, data);
    }
}

// Result

void BoxesCode::finalizeBox(int h) {
    const Cell3DPosition p = hostBlock->position;

    Box b;
    b.cmin = p;
    b.cmax = Cell3DPosition(p[0] + w - 1, p[1] + d - 1, p[2] + h - 1);

    allBoxes.push_back(b);
    paintBox(b, static_cast<int>(allBoxes.size()) - 1);
    printStats(b);
}

void BoxesCode::paintBox(const Box &b, int index) const {
    const float *c = PALETTE[index % PALETTE_SIZE];
    for (int x = b.cmin[0]; x <= b.cmax[0]; ++x)
        for (int y = b.cmin[1]; y <= b.cmax[1]; ++y)
            for (int z = b.cmin[2]; z <= b.cmax[2]; ++z) {
                BuildingBlock *blk =
                    getWorld()->getBlockByPosition(Cell3DPosition(x, y, z));
                if (blk != nullptr) blk->setColor(Color(c[0], c[1], c[2]));
            }
}

void BoxesCode::printStats(const Box &b) const {
    // Boxes that are completely inside another one can be neglected
    int kept = 0;
    for (size_t i = 0; i < allBoxes.size(); ++i) {
        bool contained = false;
        for (size_t j = 0; j < allBoxes.size() && !contained; ++j) {
            if (i == j) continue;
            if (inside(allBoxes[i], allBoxes[j]) &&
                !(inside(allBoxes[j], allBoxes[i]) && j > i)) {
                contained = true;
            }
        }
        if (!contained) ++kept;
    }

    cout << "[Boxes] box #" << allBoxes.size()
         << "  Cmin=" << b.cmin << "  Cmax=" << b.cmax
         << "  | boxes so far = " << allBoxes.size()
         << "  | non-contained = " << kept
         << "  | messages = " << messageCount
         << "  | time = " << getScheduler()->now() << " us"
         << endl;
}

BaseSimulator::BlockCode *
BoxesCode::buildNewBlockCode(BaseSimulator::BuildingBlock *host) {
    return new BoxesCode(static_cast<BlinkyBlocks::BlinkyBlocksBlock *>(host));
}