#ifndef tpAppMotion2Code_H_
#define tpAppMotion2Code_H_

/**
 * @author Muhammad Omais Rana, Muhammad Shiraz
 * @date 2026-09-28
 **/

#include "robots/slidingCubes/slidingCubesSimulator.h"
#include "robots/slidingCubes/slidingCubesWorld.h"
#include "robots/slidingCubes/slidingCubesBlockCode.h"

static const int ELECTFIRST_MSG_ID = 1001;

using namespace SlidingCubes;

class TpAppMotion2Code : public SlidingCubesBlockCode {
public:
    bool isLocked = false;
private:
    SlidingCubesBlock *module = nullptr;
    bool isLeader=false;
    Color myColor=BLACK;
    Cell3DPosition previousPosition;
    bool hasPrevious = false;

public :
    TpAppMotion2Code(SlidingCubesBlock *host);
    ~TpAppMotion2Code() {};

    void startup() override;
    void myElectFirstFunc(std::shared_ptr<Message>_msg, P2PNetworkInterface *sender);
    void parseUserElements(TiXmlDocument *config) override;
    void parseUserBlockElements(TiXmlElement *config) override;
    void onMotionEnd() override;
    void onGlDraw() override;
    void moveToNextStep();
    void passBaton();

    bool updateGoal();
    BuildingBlock* getBlockAt(const Cell3DPosition &pos);

    static BlockCode *buildNewBlockCode(BuildingBlock *host) {
        return(new TpAppMotion2Code((SlidingCubesBlock*)host));
    }
};

#endif /* tpAppMotion2Code_H_ */