// authors: Muhammad Omais Rana, Muhammad Shiraz
#ifndef tpAppMotionCode_H_
#define tpAppMotionCode_H_

#include "robots/slidingCubes/slidingCubesSimulator.h"
#include "robots/slidingCubes/slidingCubesWorld.h"
#include "robots/slidingCubes/slidingCubesBlockCode.h"

static const int ELECTFIRST_MSG_ID = 1001;

using namespace SlidingCubes;

class TpAppMotionCode : public SlidingCubesBlockCode {
private:
    SlidingCubesBlock *module = nullptr;
    bool isLeader=false;
    Color myColor=BLACK;
    Cell3DPosition previousPosition;
    bool hasPrevious = false;

public :
    TpAppMotionCode(SlidingCubesBlock *host);
    ~TpAppMotionCode() {};

    void startup() override;
    void myElectFirstFunc(std::shared_ptr<Message>_msg, P2PNetworkInterface *sender);
    void parseUserElements(TiXmlDocument *config) override;
    void parseUserBlockElements(TiXmlElement *config) override;
    void onMotionEnd() override;
    void onGlDraw() override;
    void moveToNextStep();
    void passBaton();

    static BlockCode *buildNewBlockCode(BuildingBlock *host) {
        return(new TpAppMotionCode((SlidingCubesBlock*)host));
    }
};

#endif /* tpAppMotionCode_H_ */