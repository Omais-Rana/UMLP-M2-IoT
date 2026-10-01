#ifndef BorderSearchCode_H_
#define BorderSearchCode_H_
#include "robots/blinkyBlocks/blinkyBlocksSimulator.h"
#include "robots/blinkyBlocks/blinkyBlocksWorld.h"
#include "robots/blinkyBlocks/blinkyBlocksBlockCode.h"
#include <map>
#include <memory>
#include <vector>

class BorderSearchCode : public BlinkyBlocks::BlinkyBlocksBlockCode {
public:
    enum Direction { FRONT=0, RIGHT=1, BACK=2, LEFT=3 };

    struct BorderSegment {
        std::uint8_t direction;
        std::uint16_t length;
        BorderSegment() : direction(FRONT), length(0) {}
        BorderSegment(int d, std::uint16_t l) : direction(static_cast<std::uint8_t>(d)), length(l) {}
    };

    struct BorderSearchData {
        int prevDir;
        Cell3DPosition initiatorPos;
        Cell3DPosition startCorner;
        Cell3DPosition currentCorner;
        std::vector<BorderSegment> segments;
        BorderSearchData() : prevDir(FRONT), initiatorPos(0,0,0), startCorner(0,0,0), currentCorner(0,0,0) {}
        BorderSearchData(int d,const Cell3DPosition& init,const Cell3DPosition& start,const Cell3DPosition& current,const std::vector<BorderSegment>& s)
            : prevDir(d), initiatorPos(init), startCorner(start), currentCorner(current), segments(s) {}
    };

    static const int BORDER_SEARCH_MSG = 1001;
    explicit BorderSearchCode(BlinkyBlocks::BlinkyBlocksBlock *host);
    ~BorderSearchCode() override = default;
    void startup() override;
    static BaseSimulator::BlockCode *buildNewBlockCode(BaseSimulator::BuildingBlock *host);

private:
    int getNextDir(int prevDir) const;
    bool isInitiator() const;
    bool isOnBorder() const;
    bool isEmpty(const Cell3DPosition& p) const;
    Cell3DPosition nextPosition(const Cell3DPosition& p, int dir) const;
    std::uint16_t segmentLength(const Cell3DPosition& from,const Cell3DPosition& to,int dir) const;
    void printCompressedBorder(const BorderSearchData& data) const;
    void sendBorderSearch(int nextDir,const Cell3DPosition& initiatorPos,const Cell3DPosition& startCorner,const Cell3DPosition& currentCorner,const std::vector<BorderSegment>& segments);
    void handleBorderSearch(std::shared_ptr<Message> msg,P2PNetworkInterface *sender);
    std::map<int, Cell3DPosition> dirInit;
};
#endif
