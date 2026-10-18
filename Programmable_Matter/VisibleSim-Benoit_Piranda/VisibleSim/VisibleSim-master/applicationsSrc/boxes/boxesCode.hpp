#ifndef BoxesCode_H_
#define BoxesCode_H_

#include "robots/blinkyBlocks/blinkyBlocksSimulator.h"
#include "robots/blinkyBlocks/blinkyBlocksWorld.h"
#include "robots/blinkyBlocks/blinkyBlocksBlockCode.h"

#include <map>
#include <memory>
#include <vector>

/* For better understanding
 * Stages (all asynchronous, message passing between neighbours only):
 *   1. d      : SET_D_MSG travels back -> front, d = rank in the line.
 *   2. Rmin   : needs d_left, obtained with D_INFO_MSG (left -> right).
 *   3. w      : FIND_W_MSG goes right, SET_W_MSG comes back left.
 *   4. Cmin   : INFO_UP_MSG gives (Rmin, d, w) to the module above; an Rmin
 *               whose module below is an Rmin with the same (d, w) is NOT Cmin.
 *   5. height : each Cmin sends HEIGHT_MSG upwards; the answer HEIGHT_REP_MSG
 *               comes back down. Box = (x,y,z) .. (x+w-1, y+d-1, z+h-1).
 */
class BoxesCode : public BlinkyBlocks::BlinkyBlocksBlockCode {
public:
    enum MsgType {
        SET_D_MSG      = 2001,
        D_INFO_MSG     = 2002,
        FIND_W_MSG     = 2003,
        SET_W_MSG      = 2004,
        INFO_UP_MSG    = 2005,
        HEIGHT_MSG     = 2006,
        HEIGHT_REP_MSG = 2007
    };

    struct BoxData {
        Cell3DPosition origin;
        int d;
        int w;
        int h;
        bool flag;
        BoxData() : origin(0, 0, 0), d(0), w(0), h(0), flag(false) {}
    };

    struct Box {
        Cell3DPosition cmin;
        Cell3DPosition cmax;
    };

    explicit BoxesCode(BlinkyBlocks::BlinkyBlocksBlock *host);
    ~BoxesCode() override = default;

    void startup() override;
    static BaseSimulator::BlockCode *buildNewBlockCode(BaseSimulator::BuildingBlock *host);

private:
    Cell3DPosition at(int dx, int dy, int dz) const;
    bool isEmpty(const Cell3DPosition &p) const;

    void sendTo(const Cell3DPosition &target, int type, const BoxData &data);

    void setD(int value);
    void tryRmin();
    void markReady();
    void finishW();
    void tryCmin();
    void processFind(const BoxData &data);
    void processHeight(const BoxData &data);
    void sendInfoUp();
    void finalizeBox(int h);
    void paintBox(const Box &b, int index) const;
    void printStats(const Box &b) const;

    void onSetD(const BoxData &data);
    void onDInfo(const BoxData &data);
    void onFindW(const BoxData &data);
    void onSetW(const BoxData &data);
    void onInfoUp(const BoxData &data);
    void onHeight(const BoxData &data);
    void onHeightRep(const BoxData &data);

    int d;                 // 0 = not yet known
    int w;                 // 0 = not yet known (only meaningful for Rmin)
    bool dLeftKnown;
    int dLeft;
    bool rminDecided;
    bool isRmin;
    bool boxReady;         // Rmin decision (and w if Rmin) are final
    bool belowEmpty;
    bool belowKnown;
    bool belowRmin;
    int belowD;
    int belowW;
    bool cminDecided;
    bool isCmin;
    std::vector<BoxData> pendingFind;    // FIND_W_MSG received before d is set
    std::vector<BoxData> pendingHeight;  // HEIGHT_MSG received before box is ready

    static std::vector<Box> allBoxes;
    static unsigned long messageCount;
};

#endif