#include "BorderSearchCode.hpp"

BorderCode::BorderCode(BlinkyBlocksBlock *host):BlinkyBlocksBlockCode(host),module(host) {
    if (not host) return;
}

bool BorderCode::isEmpty(int x, int y) {
    Cell3DPosition targetPos(x, y, 0);

    auto &map = BlinkyBlocks::getWorld()->getMap();

    for (auto it = map.begin(); it != map.end(); ++it) {
        if (it->second->position == targetPos) {
            return false;
        }
    }
    return true;
}

bool BorderCode::isInitiator() {
    int x = module->position[0];
    int y = module->position[1];

    bool empty_y_minus_1 = isEmpty(x, y - 1);
    bool empty_x_minus_1 = isEmpty(x - 1, y);
    bool not_empty_x_plus_1_y_minus_1 = !isEmpty(x + 1, y - 1);

    return empty_y_minus_1 && (empty_x_minus_1 || not_empty_x_plus_1_y_minus_1);
}

void BorderCode::startup() {
    console << "start " << getId() << "\n";

    if (isInitiator()) {
        setColor(YELLOW);
    } else {
        setColor(LIGHTGREY);
    }
}