#ifndef BorderCode_H_
#define BorderCode_H_

#include "robots/blinkyBlocks/blinkyBlocksSimulator.h"
#include "robots/blinkyBlocks/blinkyBlocksWorld.h"
#include "robots/blinkyBlocks/blinkyBlocksBlockCode.h"

using namespace BlinkyBlocks;

class BorderCode : public BlinkyBlocksBlockCode {
private:
    BlinkyBlocksBlock *module = nullptr;

public:
    BorderCode(BlinkyBlocksBlock *host);
    ~BorderCode() {};

    void startup() override;

    bool isEmpty(int x, int y);
    bool isInitiator();

    static BlockCode *buildNewBlockCode(BuildingBlock *host) {
        return(new BorderCode((BlinkyBlocksBlock*)host));
    }
};

#endif /* BorderCode_H_ */